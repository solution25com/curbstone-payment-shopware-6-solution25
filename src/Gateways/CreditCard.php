<?php

declare(strict_types=1);

namespace Curbstone\Gateways;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CreditCard extends AbstractPaymentHandler
{
    /**
     * @param EntityRepository<OrderTransactionCollection> $orderTransactionRepository
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly EntityRepository $orderRepository,
        private readonly SystemConfigService $config,
        private readonly LoggerInterface $logger,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return !\in_array($type, [PaymentHandlerType::RECURRING, PaymentHandlerType::REFUND], true);
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): RedirectResponse {
        $orderTx = $this->loadOrderTransaction($transaction->getOrderTransactionId(), $context);
        if ($orderTx === null || $orderTx->getOrder() === null) {
            $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
            throw new \RuntimeException('OrderTransaction not found or order missing for ID ' . $transaction->getOrderTransactionId());
        }

        $order = $orderTx->getOrder();

        $orderNumber    = $order->getOrderNumber() ?? $transaction->getOrderTransactionId();
        $amountTotal    = (float) $orderTx->getAmount()->getTotalPrice();
        $currencyEntity = $order->getCurrency();
        $precision      = $currencyEntity?->getItemRounding()?->getDecimals() ?? 2;
        $amountStr      = \number_format($amountTotal, $precision, '.', '');

        $customer = $order->getOrderCustomer();
        $billing  = $order->getBillingAddress();

        $deliveries = $order->getDeliveries();
        $shipping   = null;
        if ($deliveries instanceof OrderDeliveryCollection) {
            $shipping = $deliveries->first()?->getShippingOrderAddress();
        }

        $returnUrl = $this->router->generate(
            'frontend.curbstone.return',
            ['tx' => $orderTx->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $deepLinkCode = $order->getDeepLinkCode();
        if ($deepLinkCode) {
            $accountOrderUrl = $this->router->generate(
                'frontend.account.edit-order.page',
                [
                    'orderId'      => $order->getId(),
                    'deepLinkCode' => $deepLinkCode,
                ],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        } else {
            $accountOrderUrl = (string)$transaction->getReturnUrl();
        }

        $contextCookieName = 'sw-context-token';
        $contextToken      = (string) ($request->cookies->get($contextCookieName) ?? '');

        $sessionCookieName = (string) (\ini_get('session.name') ?: 'PHPSESSID');
        $sessionId         = (string) ($request->cookies->get($sessionCookieName) ?? '');

        $salesChannelId = $order->getSalesChannelId();
        $cfgDomain      = 'Curbstone.config';

        $mfCust  = $this->config->get($cfgDomain . '.customerId', $salesChannelId);
        $mfMrch  = $this->config->get($cfgDomain . '.merchantCode', $salesChannelId);
        $flow    = $this->config->get($cfgDomain . '.authCaptureFlow', $salesChannelId);
        $sandbox = $this->config->get($cfgDomain . '.sandbox', $salesChannelId);

        $mfCustStr = \is_string($mfCust) && $mfCust !== '' ? $mfCust : '00001';
        $mfMrchStr = \is_string($mfMrch) && $mfMrch !== '' ? $mfMrch : '99998';
        $flowStr   = \is_string($flow)   && $flow   !== '' ? $flow   : 'auth_only';

        $portalBase = (\is_bool($sandbox) ? $sandbox : (bool) $sandbox)
            ? 'https://c3sbx.net/curbstone/plp/'
            : 'https://c3plp.net/curbstone/plp/';

        $addr1  = \trim((string)($billing?->getStreet() ?? '') . ' ' . (string)($billing?->getAdditionalAddressLine1() ?? ''));
        $city   = (string) ($billing?->getCity() ?? '');
        $state  = (string) ($billing?->getCountryState()?->getShortCode() ?? $billing?->getCountryState()?->getName() ?? '');
        $zip    = (string) ($billing?->getZipcode() ?? '');
        $dstZip = (string) ($shipping?->getZipcode() ?? $zip);

        $mftype = 'RA';
        $mftyp2 = ($flowStr === 'auth_capture') ? 'SA' : 'PA';

        $body = [
            'MFCUST' => $mfCustStr,
            'MFMRCH' => $mfMrchStr,
            'MFTYPE' => $mftype,
            'MFTYP2' => $mftyp2,
            'MFMETH' => '02',
            'MFORDR' => $orderNumber,
            'MFREFR' => $orderTx->getId(),
            'MFADD1' => \mb_substr($addr1, 0, 40),
            'MFCITY' => \mb_substr($city, 0, 30),
            'MFSTAT' => \mb_substr($state, 0, 10),
            'MFZIPC' => \mb_substr($zip, 0, 10),
            'MFDSTZ' => \mb_substr($dstZip, 0, 10),
            'MFAMT1' => $amountStr,
            'MFUSER' => (string) ($customer?->getEmail() ?? 'guest'),
            'MPTRGT' => $returnUrl,
            'MPCUST' => $mfCustStr,
            'MPCUSF' => (string) ($customer?->getCustomerNumber() ?? ''),
        ];

        try {
            $client   = new \GuzzleHttp\Client(['timeout' => 10, 'verify' => false]);
            $response = $client->post($portalBase . '?action=init', [
                'form_params' => $body,
            ]);
        } catch (\Throwable $e) {
            $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
            $this->logger->error('Curbstone PLP init HTTP error', [
                'error'              => $e->getMessage(),
                'orderTransactionId' => $transaction->getOrderTransactionId(),
            ]);
            throw new \RuntimeException('Curbstone PLP init HTTP error.');
        }

        $result = \json_decode((string) $response->getBody(), true);
        if (!\is_array($result) || empty($result['MFSESS'])) {
            $this->transactionStateHandler->fail($transaction->getOrderTransactionId(), $context);
            $this->logger->error('Curbstone PLP init failed', [
                'result'             => $result,
                'orderTransactionId' => $transaction->getOrderTransactionId(),
            ]);
            throw new \RuntimeException('Curbstone PLP init failed.');
        }

        $mfSess    = (string) $result['MFSESS'];
        $iframeUrl = $portalBase . '?MFSESS=' . \rawurlencode($mfSess) . '&mode=embedded';

        $this->updateTxCustomFields($orderTx, $context, [
            'curbstone' => [
                'shopReturnUrl'      => $accountOrderUrl,
                'mfSess'             => $mfSess,
                'iframeUrl'          => $iframeUrl,
                'portalBase'         => $portalBase,
                'returnEndpoint'     => $returnUrl,
                'flow'               => $flowStr,
                'contextCookieName'  => $contextCookieName,
                'contextToken'       => $contextToken,
                'sessionCookieName'  => $sessionCookieName,
                'sessionId'          => $sessionId,
            ],
            'curbstone_data' => [
                'transaction_id' => $orderTx->getId(),
            ],
        ]);

        $this->updateOrderCustomFields(
            $order->getId(),
            $order->getCustomFields() ?? [],
            [
                'curbstone' => [
                    'mfSess'     => $mfSess,
                    'iframeUrl'  => $iframeUrl,
                    'portalBase' => $portalBase,
                    'flow'       => $flowStr,
                ],
            ],
            $context
        );

        // $this->transactionStateHandler->process($transaction->getOrderTransactionId(), $context);

        return new RedirectResponse($accountOrderUrl);
    }

    private function loadOrderTransaction(string $orderTransactionId, Context $context): ?OrderTransactionEntity
    {
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('order');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('order.deliveries');
        $criteria->addAssociation('order.deliveries.shippingOrderAddress');
        $criteria->addAssociation('order.addresses');
        $criteria->addAssociation('order.orderCustomer');

        /** @var OrderTransactionEntity|null $tx */
        $tx = $this->orderTransactionRepository->search($criteria, $context)->first();

        return $tx;
    }

    /**
     * @param array<string, mixed> $merge
     */
    private function updateTxCustomFields(OrderTransactionEntity $tx, Context $context, array $merge): void
    {
        $custom = $tx->getCustomFields() ?? [];

        foreach ($merge as $k => $v) {
            if (\is_array($v) && isset($custom[$k]) && \is_array($custom[$k])) {
                $custom[$k] = \array_replace_recursive($custom[$k], $v);
            } else {
                $custom[$k] = $v;
            }
        }

        $this->orderTransactionRepository->update([[
            'id'           => $tx->getId(),
            'customFields' => $custom,
        ]], $context);
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $merge
     */
    private function updateOrderCustomFields(string $orderId, array $existing, array $merge, Context $context): void
    {
        foreach ($merge as $k => $v) {
            if (\is_array($v) && isset($existing[$k]) && \is_array($existing[$k])) {
                $existing[$k] = \array_replace_recursive($existing[$k], $v);
            } else {
                $existing[$k] = $v;
            }
        }

        $this->orderRepository->update([[
            'id'           => $orderId,
            'customFields' => $existing,
        ]], $context);
    }
}
