<?php

declare(strict_types=1);

namespace Curbstone\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

final class CustomFieldUpdater
{
    /**
     * @param EntityRepository<OrderTransactionCollection> $orderTransactionRepository
     * @param EntityRepository<OrderCollection>            $orderRepository
     */
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository,
        private readonly EntityRepository $orderRepository,
    ) {
    }

    /**
     * @param array<string,mixed> $merge
     */
    public function updateTransaction(OrderTransactionEntity $tx, Context $context, array $merge): void
    {
        /** @var array<string,mixed> $custom */
        $custom = $tx->getCustomFields() ?? [];

        $custom = $this->mergeRecursive($custom, $merge);

        $this->orderTransactionRepository->update([[
            'id'           => $tx->getId(),
            'customFields' => $custom,
        ]], $context);
    }

    /**
     * @param array<string,mixed>|null $existing
     * @param array<string,mixed>      $merge
     */
    public function updateOrder(string $orderId, ?array $existing, array $merge, Context $context): void
    {
        /** @var array<string,mixed> $base */
        $base = $existing ?? [];

        $custom = $this->mergeRecursive($base, $merge);

        $this->orderRepository->update([[
            'id'           => $orderId,
            'customFields' => $custom,
        ]], $context);
    }

    /**
     * @param  array<string,mixed> $base
     * @param  array<string,mixed> $incoming
     * @return array<string,mixed>
     */
    private function mergeRecursive(array $base, array $incoming): array
    {
        foreach ($incoming as $k => $v) {
            if (\array_key_exists($k, $base) && \is_array($base[$k]) && \is_array($v)) {
                /** @var array<string,mixed> $baseChild */
                $baseChild = $base[$k];
                /** @var array<string,mixed> $vChild */
                $vChild = $v;

                $base[$k] = $this->mergeRecursive($baseChild, $vChild);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }
}
