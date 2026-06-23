<?php declare(strict_types=1);

namespace Curbstone\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CurbstoneTransactionService
{
    public function __construct(
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly EntityRepository             $orderTransactionRepository,
        private readonly UrlGeneratorInterface        $router,
        private readonly LoggerInterface              $logger,
    ) {}

    public function loadOrderTransactionOrFail(string $id, Context $context): OrderTransactionEntity
    {
        $criteria = (new Criteria([$id]))
            ->addAssociation('order')
            ->addAssociation('order.currency')
            ->addAssociation('order.orderCustomer')
            ->addAssociation('order.billingAddress');

        /** @var OrderTransactionEntity|null $tx */
        $tx = $this->orderTransactionRepository->search($criteria, $context)->first();

        if (!$tx || !$tx->getOrder()) {
            $this->transactionStateHandler->fail($id, $context);
            throw new \RuntimeException('Curbstone: Missing OrderTransaction.');
        }

        return $tx;
    }

    public function formatAmount(OrderTransactionEntity $orderTx): string
    {
        $order    = $orderTx->getOrder();
        $decimals = $order->getCurrency()?->getItemRounding()?->getDecimals() ?? 2;

        return number_format(
            $orderTx->getAmount()->getTotalPrice(),
            $decimals,
            '.',
            ''
        );
    }

    public function extractBillingAddress(OrderEntity $order): array
    {
        $billing = $order->getBillingAddress();

        $addr1 = trim(
            ($billing?->getStreet() ?? '') . ' ' .
            ($billing?->getAdditionalAddressLine1() ?? '')
        );
        $city  = (string) ($billing?->getCity() ?? '');
        $state = (string) (
            $billing?->getCountryState()?->getShortCode()
            ?? $billing?->getCountryState()?->getName()
            ?? ''
        );
        $zip   = (string) ($billing?->getZipcode() ?? '');

        return [
            'addr1' => $addr1,
            'city'  => $city,
            'state' => $state,
            'zip'   => $zip,
        ];
    }

    public function buildFinishUrl(OrderEntity $order): string
    {
        return $this->router->generate(
            'frontend.checkout.finish.page',
            ['orderId' => $order->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    public function validateRealChargeResponse(
        array $data,
        string $amount,
        bool $usingSavedCard,
        string $txId,
        Context $context
    ): void {
        $mfrtrn = $this->normalizeMfrtrn($data['MFRTRN'] ?? null);

        if (!$this->isRealChargeApproved($data)) {
            $this->transactionStateHandler->fail($txId, $context);

            $this->logger->error('Curbstone REAL charge not approved (provider response)', [
                'response'  => $this->sanitizeGatewayPayload($data),
                'MFRTRN'    => $mfrtrn,
                'MFRTXT'    => $data['MFRTXT'] ?? null,
                'MFATAL'    => $data['MFATAL'] ?? null,
                'hasMFSESS' => !empty($data['MFSESS']),
            ]);

            $msg = $data['MFRTXT'] ?? 'Curbstone real payment failed or declined.';
            throw new \RuntimeException($msg);
        }

        $this->logger->info('Curbstone REAL payment OK', [
            'MFSESS'         => $data['MFSESS'],
            'MFRTRN'         => $mfrtrn,
            'amount'         => $amount,
            'usingSavedCard' => $usingSavedCard,
        ]);
    }

    /**
     * Real charge must not succeed on MFSESS alone. Matches iframe return semantics in
     * CurbstoneInlinePreauthController (approved MFRTRN = UG). Add codes only per provider spec.
     */
    private function isRealChargeApproved(array $data): bool
    {
        if (empty($data['MFSESS'])) {
            return false;
        }

        if ($this->isFatalCurbstoneResponse($data)) {
            return false;
        }

        return $this->normalizeMfrtrn($data['MFRTRN'] ?? null) === 'UG';
    }

    private function normalizeMfrtrn(mixed $value): string
    {
        return strtoupper(trim((string) $value));
    }

    private function isFatalCurbstoneResponse(array $data): bool
    {
        $raw = strtoupper(trim((string) ($data['MFATAL'] ?? '')));
        if ($raw === '') {
            return false;
        }

        return \in_array($raw, ['Y', '1', 'TRUE', 'YES', 'FATAL'], true);
    }

    public function tryAuthorize(string $txId, Context $context, string $amount, float $threshold = 5000.0): void
    {
        try {
            $numericAmount = (float) $amount;
            // Orders below the $5,000 threshold must end in the authorized state.
            // Orders at or above the threshold stay in in_progress so they do not complete as a PA flow.
            $targetState = $numericAmount < $threshold ? 'authorized' : 'in_progress';
            $this->logger->info('Curbstone state transition decision', [
                'txId' => $txId,
                'amount' => $amount,
                'amountValue' => $numericAmount,
                'threshold' => $threshold,
                'targetState' => $targetState,
            ]);

            if ($numericAmount < $threshold) {
                $this->transactionStateHandler->authorize($txId, $context);
                $this->logger->info('Curbstone state transition applied', [
                    'txId' => $txId,
                    'newState' => 'authorized',
                    'reason' => 'final_authorization_under_threshold',
                ]);
            } else {
                $this->transactionStateHandler->process($txId, $context);
                $this->logger->info('Curbstone state transition applied', [
                    'txId' => $txId,
                    'newState' => 'in_progress',
                    'reason' => 'manual_review_or_capture_above_threshold',
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Curbstone: transaction cannot be authorized', [
                'exception' => $e->getMessage(),
                'txId' => $txId,
                'amount' => $amount,
            ]);
        }
    }

    public function markInProgress(string $txId, Context $context): void
    {
        try {
            $this->transactionStateHandler->process($txId, $context);
            $this->logger->info('Curbstone state transition applied', [
                'requirement' => 'high_value_manual_authorization',
                'txId' => $txId,
                'newState' => 'in_progress',
                'source' => 'checkout_high_value_branch',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Curbstone: transaction cannot be set in progress', [
                'exception' => $e->getMessage(),
                'txId' => $txId,
            ]);
        }
    }

    public function persistTransactionMeta(OrderTransactionEntity $tx, Context $context, array $merge): void
    {
        $existing = $tx->getCustomFields() ?? [];
        $combined = array_replace_recursive($existing, $merge);

        $this->orderTransactionRepository->update([[
            'id'           => $tx->getId(),
            'customFields' => $combined,
        ]], $context);
    }

    public function sanitizeGatewayPayload(array $payload): array
    {
        $sensitiveKeys = [
            'MFCARD',
            'MFCRD',
            'MFCRD4',
            'MFCRD6',
            'MFCCVV',
            'MFRCVV',
            'MFCCVC',
            'MFEXP1',
            'MFEXP2',
            'MFEXP',
            'MFEDAT',
        ];

        foreach ($sensitiveKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[redacted]';
            }
        }

        return $payload;
    }
}
