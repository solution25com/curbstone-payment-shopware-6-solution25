<?php declare(strict_types=1);

namespace Curbstone\Gateways;

use Curbstone\Config\CurbstoneConfigProvider;
use Curbstone\Service\CurbstoneCardResolver;
use Curbstone\Service\CurbstonePaymentClient;
use Curbstone\Service\CurbstoneRequestFactory;
use Curbstone\Service\CurbstoneTransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

final class CreditCard extends AbstractPaymentHandler
{
    public function __construct(
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly CurbstoneConfigProvider      $configProvider,
        private readonly LoggerInterface              $logger,
        private readonly CurbstoneRequestFactory      $requestFactory,
        private readonly CurbstoneCardResolver        $cardResolver,
        private readonly CurbstonePaymentClient       $paymentClient,
        private readonly CurbstoneTransactionService  $transactionService,
    ) {}

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return !\in_array($type, [PaymentHandlerType::RECURRING, PaymentHandlerType::REFUND], true);
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {
        $this->logger->info('Curbstone: pay() raw POST', [
            'post' => $request->request->all(),
        ]);

        $txId = $transaction->getOrderTransactionId();

        $orderTx = $this->transactionService->loadOrderTransactionOrFail($txId, $context);
        $order   = $orderTx->getOrder();

        $scId = $order->getSalesChannelId();
        $cfg  = $this->configProvider->forSalesChannel($scId);

        try {
            $orderCustomerId = $order->getOrderCustomer()?->getCustomerId();
            $cardData = $this->cardResolver->resolve($request, $txId, $orderCustomerId, $context);
        } catch (\Throwable $e) {
            $this->transactionStateHandler->fail($txId, $context);
            throw $e;
        }

        if (!isset($cardData['mfkeyp']) || $cardData['mfkeyp'] === '') {
            $this->logger->error('Curbstone: MFKEYP is empty before REAL charge', [
                'usingSavedCard' => $cardData['usingSavedCard'] ?? null,
                'cardData'       => $cardData,
                'post'           => $request->request->all(),
            ]);

            $this->transactionStateHandler->fail($txId, $context);
            throw new \RuntimeException('Curbstone: MFKEYP is missing for real payment.');
        }

        $amount      = $this->transactionService->formatAmount($orderTx);
        $billingData = $this->transactionService->extractBillingAddress($order);
        $finishUrl   = $this->transactionService->buildFinishUrl($order);
        $amountValue = (float) $amount;
        // Curbstone threshold is based on the final order total.
        // Below the threshold we must keep the payment in PA mode.
        // At or above the threshold we switch away from PA.
        $isHighValue = $amountValue >= $cfg->highValueThreshold;
        $gatewaySubtype = $isHighValue ? 'SA' : 'PA';

        $this->logger->info('Curbstone requirement-check: amount routing decision', [
            'requirement' => 'high_value_manual_authorization',
            'txId' => $txId,
            'orderId' => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'amount' => $amount,
            'amountValue' => $amountValue,
            'threshold' => $cfg->highValueThreshold,
            'isHighValue' => $isHighValue,
            'gatewaySubtype' => $gatewaySubtype,
            'expectedFlow' => $isHighValue ? 'in_progress_at_or_above_threshold' : 'authorized_under_threshold',
        ]);

        $body = $this->requestFactory->buildRealChargeBody(
            $cfg,
            $order,
            $orderTx,
            $amount,
            $billingData,
            $cardData,
            $finishUrl,
            $gatewaySubtype
        );

        $portal = $this->configProvider->plpBaseUrl($cfg->sandbox);
        $this->logger->info('Curbstone requirement-check: executing Curbstone payment request', [
            'requirement' => 'threshold_based_gateway_subtype',
            'txId' => $txId,
            'amount' => $amount,
            'threshold' => $cfg->highValueThreshold,
            'isHighValue' => $isHighValue,
            'gatewaySubtype' => $gatewaySubtype,
            'portal' => $portal,
        ]);

        $this->logger->info('Curbstone payment request payload', [
            'txId' => $txId,
            'gatewaySubtype' => $gatewaySubtype,
            'request' => $this->transactionService->sanitizeGatewayPayload($body),
        ]);

        try {
            $data = $this->paymentClient->sendRealCharge(
                $portal,
                $body,
                (bool) $cardData['usingSavedCard'],
                $cfg->verifyTls
            );
        } catch (\Throwable $e) {
            $this->transactionStateHandler->fail($txId, $context);
            throw $e;
        }

        $this->transactionService->validateRealChargeResponse(
            $data,
            $amount,
            (bool) $cardData['usingSavedCard'],
            $txId,
            $context
        );

        $sanitizedRequest  = $this->transactionService->sanitizeGatewayPayload($body);
        $sanitizedResponse = $this->transactionService->sanitizeGatewayPayload($data);

        $this->logger->info('Curbstone payment response payload', [
            'txId' => $txId,
            'gatewaySubtype' => $gatewaySubtype,
            'response' => $sanitizedResponse,
        ]);

        $this->transactionService->persistTransactionMeta($orderTx, $context, [
            'curbstone' => [
                'mfSess'        => $data['MFSESS'] ?? null,
                'preauthToken'  => $cardData['token'] ?? null,
                'mfukey'        => $cardData['mfukey'] ?? null,
                'amount'        => $amount,
                'usingSavedCard'=> $cardData['usingSavedCard'] ?? false,
                'mfkeyp'        => $cardData['mfkeyp'] ?? null,
                'gatewaySubtype'=> $gatewaySubtype,
                'highValueThreshold' => $cfg->highValueThreshold,

                'realCharge'    => [
                    'request'  => $sanitizedRequest,
                    'response' => $sanitizedResponse,
                ],
            ],
        ]);

        $this->transactionService->tryAuthorize($txId, $context,  $amount, $cfg->highValueThreshold);

        return new RedirectResponse($finishUrl);
    }
}
