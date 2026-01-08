<?php

declare(strict_types=1);

namespace Curbstone\Subscriber;

use Curbstone\Storefront\Struct\CheckoutTemplateCustomData;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class CheckoutFinishEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutFinishPageLoadedEvent::class => 'onCheckoutFinish',
        ];
    }

    public function onCheckoutFinish(CheckoutFinishPageLoadedEvent $event): void
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
