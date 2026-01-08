<?php

declare(strict_types=1);

namespace Curbstone\Subscriber;

use Curbstone\Storefront\Struct\CheckoutTemplateCustomData;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class AccountOrderEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            AccountEditOrderPageLoadedEvent::class => 'onAccountOrderLoaded',
        ];
    }

    public function onAccountOrderLoaded(AccountEditOrderPageLoadedEvent $event): void
    {
        $order = $event->getPage()->getOrder();
        /** @phpstan-ignore-next-line */
        if (!$order) {
            return;
        }

        $transactions = $order->getTransactions();
        $tx = $transactions ? $transactions->last() : null;
        if (!$tx) {
            return;
        }

        $cf = $tx->getCustomFields() ?? [];
        $curbstone = $cf['curbstone'] ?? null;

        if (!\is_array($curbstone) || empty($curbstone['iframeUrl'])) {
            return;
        }

        $data = new CheckoutTemplateCustomData();
        $data->assign([
            'template'  => '@Curbstone/storefront/curbstone-payment/curbstone-payment.html.twig',
            'iframeUrl' => $curbstone['iframeUrl'],
        ]);

        $event->getPage()->addExtension(CheckoutTemplateCustomData::EXTENSION_NAME, $data);
    }
}
