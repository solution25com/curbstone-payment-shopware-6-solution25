<?php

declare(strict_types=1);

namespace Curbstone\Subscriber;

use Curbstone\Gateways\CreditCard;
use Curbstone\Storefront\Struct\CheckoutTemplateCustomData;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

final class CheckoutConfirmEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemConfigService $config,
        private readonly TemplateFinder $templateFinder,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutConfirmLoaded',
        ];
    }

    public function onCheckoutConfirmLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        // getPaymentMethod() is non-null here; just check the handler.
        $handler = $event->getSalesChannelContext()->getPaymentMethod()->getHandlerIdentifier();
        if ($handler !== CreditCard::class) {
            return;
        }

        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();
        $cfgDomain      = 'Curbstone.config';

        // Read configured template; only accept non-empty strings.
        $configured = $this->config->get($cfgDomain . '.confirmTemplate', $salesChannelId);
        $templatePath = (is_string($configured) && $configured !== '')
            ? $configured
            : '@Storefront/curbstone-payment/curbstone-payment.html.twig';

        $resolved = null;
        try {
            // Will throw if not resolvable
            $this->templateFinder->find($templatePath);
            $resolved = $templatePath;
        } catch (\Throwable $e) {
            $this->logger->warning('Curbstone: confirm template not found or invalid', [
                'template' => $templatePath,
                'error'    => $e->getMessage(),
            ]);
        }

        $data = new CheckoutTemplateCustomData();

        $sandboxCfg = $this->config->get($cfgDomain . '.sandbox', $salesChannelId);
        $mode = (is_bool($sandboxCfg) ? $sandboxCfg : (bool)$sandboxCfg) ? 'sandbox' : 'live';

        /** @var array<string,mixed> $payload */
        $payload = [
            'mode'    => $mode,
            'handler' => CreditCard::class,
        ];

        if (\is_string($resolved)) {
            $payload['template'] = $resolved;
        }


        $data->assign($payload);

        $event->getPage()->addExtension(CheckoutTemplateCustomData::EXTENSION_NAME, $data);
    }
}
