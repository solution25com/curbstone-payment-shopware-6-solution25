<?php

declare(strict_types=1);

namespace Curbstone\Subscriber;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class AccountOrderEditTxIdSubscriber implements EventSubscriberInterface
{
    /**
     * @param EntityRepository<OrderTransactionCollection> $orderTransactionRepository
     */
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AccountEditOrderPageLoadedEvent::class => 'onAccountOrderEditLoaded',
        ];
    }

    public function onAccountOrderEditLoaded(AccountEditOrderPageLoadedEvent $event): void
    {
        // Order is guaranteed on this page
        $order = $event->getPage()->getOrder();

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('orderId', $order->getId()))
            ->addAssociation('stateMachineState')
            ->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING))
            ->setLimit(1);

        /** @var OrderTransactionEntity|null $tx */
        $tx = $this->orderTransactionRepository
            ->search($criteria, $event->getContext())
            ->first();

        if ($tx === null) {
            return;
        }

        $event->getPage()->addExtension(
            'curbstone',
            new ArrayStruct([
                'transactionId' => $tx->getId(),
            ])
        );
    }
}
