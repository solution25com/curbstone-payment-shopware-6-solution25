<?php declare(strict_types=1);

namespace Curbstone\Subscriber;

use Curbstone\Config\CurbstoneConfigProvider;
use Curbstone\Service\CurbstoneRefundService;
use Curbstone\Service\CurbstoneTransactionService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class CurbstoneRefundSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CurbstoneConfigProvider     $configProvider,
        private readonly CurbstoneTransactionService $transactionService,
        private readonly CurbstoneRefundService      $refundService,
        private readonly LoggerInterface             $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'state_enter.order_transaction.state.refunded'           => 'onRefunded',
            'state_enter.order_transaction.state.refunded_partially' => 'onRefundedPartially',
            'state_enter.order_transaction.state.cancelled'          => 'onCancelled',
        ];
    }

    public function onRefunded(OrderStateMachineStateChangeEvent $event): void
    {
        $context = $event->getContext();
        $txId = $this->getTransactionIdFromEvent($event);
        $salesChannelId = $event->getSalesChannelId();

        if ($this->configProvider->isSubscribersDisabled($salesChannelId)) {
            $this->logger->info('[Curbstone] Subscribers disabled in config → skipping CheckoutConfirmEventSubscriber.', [
                'salesChannelId' => $salesChannelId,
            ]);
            return;
        }
        if ($txId === null) {
            $this->logger->warning('Curbstone refund: could not determine transaction id for full refund', [
                'orderId' => $event->getOrder()?->getId(),
            ]);

            return;
        }

        try {
            $tx = $this->transactionService->loadOrderTransactionOrFail($txId, $context);
        } catch (\Throwable $e) {
            $this->logger->error('Curbstone refund: cannot load transaction for full refund', [
                'txId'      => $txId,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $amount = $this->transactionService->formatAmount($tx);

        $this->logger->info('Curbstone refund: state_enter.refunded → full refund', [
            'txId'   => $txId,
            'amount' => $amount,
        ]);

        $this->refundService->refund($tx, $amount, $context);
    }

    public function onRefundedPartially(OrderStateMachineStateChangeEvent $event): void
    {
        $context = $event->getContext();
        $txId    = $this->getTransactionIdFromEvent($event);

        if ($txId === null) {
            $this->logger->warning('Curbstone refund: could not determine transaction id for partial refund', [
                'orderId' => $event->getOrder()?->getId(),
            ]);

            return;
        }

        try {
            $tx = $this->transactionService->loadOrderTransactionOrFail($txId, $context);
        } catch (\Throwable $e) {
            $this->logger->error('Curbstone refund: cannot load transaction for partial refund', [
                'txId'      => $txId,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $amount = $this->refundService->getLatestRefundAmountForTransaction($txId, $context);
        if ($amount === null) {
            $this->logger->warning('Curbstone refund: no refund entity found for partial refund', [
                'txId' => $txId,
            ]);

            return;
        }

        $this->logger->info('Curbstone refund: state_enter.refunded_partially → partial refund', [
            'txId'   => $txId,
            'amount' => $amount,
        ]);

        $this->refundService->refund($tx, $amount, $context);
    }

    public function onCancelled(OrderStateMachineStateChangeEvent $event): void
    {
        $context = $event->getContext();
        $txId    = $this->getTransactionIdFromEvent($event);

        if ($txId === null) {
            $this->logger->warning('Curbstone void: could not determine transaction id for cancellation', [
                'orderId' => $event->getOrder()?->getId(),
            ]);

            return;
        }

        try {
            $tx = $this->transactionService->loadOrderTransactionOrFail($txId, $context);
        } catch (\Throwable $e) {
            $this->logger->error('Curbstone void: cannot load transaction for cancellation', [
                'txId'      => $txId,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $state = $tx->getStateMachineState()?->getTechnicalName();

        // Only void if it was authorized
        if ($state !== 'authorized') {
            $this->logger->info('Curbstone void: skipping void because transaction is not authorized', [
                'txId'  => $txId,
                'state' => $state,
            ]);

            return;
        }

        $this->logger->info('Curbstone void: state_enter.cancelled → void authorization', [
            'txId' => $txId,
        ]);

        $this->refundService->voidAuthorization($tx, $context);
    }

    private function getTransactionIdFromEvent(OrderStateMachineStateChangeEvent $event): ?string
    {
        $order = $event->getOrder();
        if ($order === null) {
            $this->logger->warning('Curbstone refund: OrderStateMachineStateChangeEvent without order');

            return null;
        }

        $transactions = $order->getTransactions();
        if ($transactions === null || $transactions->count() === 0) {
            $this->logger->warning('Curbstone refund: order has no transactions', [
                'orderId' => $order->getId(),
            ]);

            return null;
        }

        $transaction = $transactions->last();

        return $transaction?->getId();
    }
}
