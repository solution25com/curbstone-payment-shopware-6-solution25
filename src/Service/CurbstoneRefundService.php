<?php declare(strict_types=1);

namespace Curbstone\Service;

use Curbstone\Config\CurbstoneConfigProvider;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

final class CurbstoneRefundService
{
    public function __construct(
        private readonly CurbstoneConfigProvider   $configProvider,
        private readonly CurbstoneTransactionService $transactionService,
        private readonly CurbstonePaymentClient    $paymentClient,
        private readonly CurbstoneRequestFactory   $requestFactory,
        private readonly EntityRepository            $orderTransactionCaptureRefundRepository,
        private readonly LoggerInterface           $logger,
    ) {}
    public function refund(
        OrderTransactionEntity $tx,
        string $amount,
        Context $context
    ): void {
        $order = $tx->getOrder();
        if (!$order instanceof OrderEntity) {
            $this->logger->error('Curbstone refund: transaction has no order', [
                'txId' => $tx->getId(),
            ]);

            return;
        }

        $customFields = $tx->getCustomFields() ?? [];
        $meta         = $customFields['curbstone'] ?? null;

        if (!\is_array($meta)) {
            $this->logger->error('Curbstone refund: no curbstone meta on transaction', [
                'txId' => $tx->getId(),
            ]);

            return;
        }

        $salesChannelId = $order->getSalesChannelId();
        $cfg            = $this->configProvider->forSalesChannel($salesChannelId);
        $portal         = $this->configProvider->plpBaseUrl($cfg->sandbox);

        $body = $this->requestFactory->buildRefundBody(
            $cfg,
            $order,
            $tx,
            $meta,
            $amount
        );

        $this->logger->info('Curbstone refund: sending refund request', [
            'txId'           => $tx->getId(),
            'orderNumber'    => $order->getOrderNumber(),
            'amount'         => $amount,
            'curbstoneMeta'  => [
                'mfSess' => $meta['mfSess'] ?? null,
                'mfkeyp' => $meta['mfkeyp'] ?? null,
            ],
        ]);

        try {
            $response = $this->paymentClient->sendRefund($portal, $body, $cfg->verifyTls);
        } catch (\Throwable $e) {
            $this->logger->error('Curbstone refund: HTTP/transport error', [
                'txId'      => $tx->getId(),
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $this->validateRefundResponse($response, $amount, $tx, $context);
        $this->persistRefundMeta($tx, $body, $response, $amount, $context);
    }

    public function voidAuthorization(
        OrderTransactionEntity $tx,
        Context $context
    ): void {
        $order = $tx->getOrder();
        if (!$order instanceof OrderEntity) {
            $this->logger->error('Curbstone void: transaction has no order', [
                'txId' => $tx->getId(),
            ]);

            return;
        }

        $customFields = $tx->getCustomFields() ?? [];
        $meta         = $customFields['curbstone'] ?? null;

        if (!\is_array($meta)) {
            $this->logger->error('Curbstone void: no curbstone meta on transaction', [
                'txId' => $tx->getId(),
            ]);

            return;
        }

        $salesChannelId = $order->getSalesChannelId();
        $cfg            = $this->configProvider->forSalesChannel($salesChannelId);
        $portal         = $this->configProvider->plpBaseUrl($cfg->sandbox);

        $body = $this->requestFactory->buildVoidBody(
            $cfg,
            $order,
            $tx,
            $meta
        );

        $this->logger->info('Curbstone void: sending void request', [
            'txId'          => $tx->getId(),
            'orderNumber'   => $order->getOrderNumber(),
            'curbstoneMeta' => [
                'mfSess' => $meta['mfSess'] ?? null,
                'mfkeyp' => $meta['mfkeyp'] ?? null,
            ],
        ]);

        try {
            $response = $this->paymentClient->sendVoid($portal, $body, $cfg->verifyTls);
        } catch (\Throwable $e) {
            $this->logger->error('Curbstone void: HTTP/transport error', [
                'txId'      => $tx->getId(),
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $this->validateVoidResponse($response, $tx, $context);
        $this->persistVoidMeta($tx, $body, $response, $context);
    }

    public function getLatestRefundAmountForTransaction(string $txId, Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addAssociation('orderTransactionCapture')
            ->addFilter(new EqualsFilter('orderTransactionCapture.orderTransactionId', $txId))
            ->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING))
            ->setLimit(1);
    
        /** @var object|null $refund */
        $refund = $this->orderTransactionCaptureRefundRepository->search($criteria, $context)->first();
        if ($refund === null) {
            return null;
        }
    
        $amountValue = $refund->getAmount()->getTotalPrice();
    
        return number_format((float) $amountValue, 2, '.', '');
    }
    

    private function validateRefundResponse(
        array $response,
        string $amount,
        OrderTransactionEntity $tx,
        Context $context
    ): void {
        if (empty($response['MFSESS'])) {
            $this->logger->error('Curbstone refund: missing MFSESS in response', [
                'txId'     => $tx->getId(),
                'response' => $this->transactionService->sanitizeGatewayPayload($response),
            ]);

            return;
        }

        $this->logger->info('Curbstone refund: OK', [
            'txId'   => $tx->getId(),
            'MFSESS' => $response['MFSESS'],
            'amount' => $amount,
        ]);
    }

    private function validateVoidResponse(
        array $response,
        OrderTransactionEntity $tx,
        Context $context
    ): void {
        if (empty($response['MFSESS'])) {
            $this->logger->error('Curbstone void: missing MFSESS in response', [
                'txId'     => $tx->getId(),
                'response' => $this->transactionService->sanitizeGatewayPayload($response),
            ]);

            return;
        }

        $this->logger->info('Curbstone void: OK', [
            'txId'   => $tx->getId(),
            'MFSESS' => $response['MFSESS'],
        ]);
    }

    private function persistRefundMeta(
        OrderTransactionEntity $tx,
        array $requestBody,
        array $response,
        string $amount,
        Context $context
    ): void {
        $sanitizedRequest  = $this->transactionService->sanitizeGatewayPayload($requestBody);
        $sanitizedResponse = $this->transactionService->sanitizeGatewayPayload($response);
    
        $existing = $tx->getCustomFields() ?? [];
        $curb     = $existing['curbstone'] ?? [];
        $refunds  = $curb['refunds'] ?? [];
    
        $refunds[] = [
            'amount'    => $amount,
            'mfSess'    => $response['MFSESS'] ?? null,
            'mfTran'    => $response['MFTRAN'] ?? null, 
            'mfOrid'    => $response['MFORID'] ?? null,
            'request'   => $sanitizedRequest,
            'response'  => $sanitizedResponse,
            'createdAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    
        $merge = [
            'curbstone' => array_replace($curb, [
                'refunds' => $refunds,
            ]),
        ];
    
        $this->transactionService->persistTransactionMeta($tx, $context, $merge);
    }
    

    private function persistVoidMeta(
        OrderTransactionEntity $tx,
        array $requestBody,
        array $response,
        Context $context
    ): void {
        $sanitizedRequest  = $this->transactionService->sanitizeGatewayPayload($requestBody);
        $sanitizedResponse = $this->transactionService->sanitizeGatewayPayload($response);
    
        $existing = $tx->getCustomFields() ?? [];
        $curb     = $existing['curbstone'] ?? [];
    
        $voidMeta = [
            'mfSess'    => $response['MFSESS'] ?? null,
            'mfTran'    => $response['MFTRAN'] ?? null,
            'mfOrid'    => $response['MFORID'] ?? null,
            'request'   => $sanitizedRequest,
            'response'  => $sanitizedResponse,
            'createdAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    
        $merge = [
            'curbstone' => array_replace($curb, [
                'void' => $voidMeta,
            ]),
        ];
    
        $this->transactionService->persistTransactionMeta($tx, $context, $merge);
    }
    
}
