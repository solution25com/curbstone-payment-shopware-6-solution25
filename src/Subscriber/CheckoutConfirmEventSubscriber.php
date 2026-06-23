<?php declare(strict_types=1);

namespace Curbstone\Subscriber;

use Curbstone\Gateways\CreditCard;
use Curbstone\Config\CurbstoneConfigProvider;
use Curbstone\Service\CurbstoneVaultService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;

final class CheckoutConfirmEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CurbstoneConfigProvider $configProvider,
        private readonly TemplateFinder          $templateFinder,
        private readonly CurbstoneVaultService   $vaultService,
        private readonly LoggerInterface         $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onCheckoutConfirmLoaded',
            AccountEditOrderPageLoadedEvent::class => 'onAccountEditOrderLoaded',
        ];
    }

    public function onCheckoutConfirmLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $this->injectCurbstoneData(
            $event->getPage(),
            $event->getSalesChannelContext(),
            $event->getRequest(),
        );
    }

    public function onAccountEditOrderLoaded(AccountEditOrderPageLoadedEvent $event): void
    {
        $this->injectCurbstoneData(
            $event->getPage(),
            $event->getSalesChannelContext(),
            $event->getRequest(),
        );
    }

    private function injectCurbstoneData(object $page, SalesChannelContext $scContext, Request $request): void
    {
        $payment   = $scContext->getPaymentMethod();
        $customer  = $scContext->getCustomer();
        $salesChannelId = $scContext->getSalesChannelId();

        if (!$payment) {
            $this->logger->warning('[Curbstone] No payment method on confirm page.');
            return;
        }

        $handler = $payment->getHandlerIdentifier();

        $this->logger->info('[Curbstone] Detected handler', [
            'handler'         => $handler,
            'expectedHandler' => CreditCard::class,
        ]);

        if ($handler !== CreditCard::class) {
            $this->logger->info('[Curbstone] Non-Curbstone handler, skipping.');
            return;
        }

        $session    = $request?->getSession();
        $sessionKey = 'curbstone_preauth_' . $scContext->getToken();
        $preauth    = $session?->get($sessionKey);

        if (\is_array($preauth)) {
            $page->addExtension('curbstone_preauth', new ArrayStruct($preauth));

            $this->logger->info('[Curbstone] Injected curbstone_preauth', [
                'status' => $preauth['status'] ?? null,
                'token'  => $preauth['token'] ?? null,
                'mfukey' => $preauth['mfukey'] ?? null,
            ]);
        } else {
            $this->logger->info('[Curbstone] No usable preauth data for this context.', [
                'sessionKey' => $sessionKey,
                'value'      => $preauth,
            ]);
            $preauth = null;
        }

        if ($this->configProvider->isSubscribersDisabled($salesChannelId)) {
            $this->logger->info('[Curbstone] Subscribers disabled in config → skipping CheckoutConfirmEventSubscriber.', [
                'salesChannelId' => $salesChannelId,
            ]);
            return;
        }

        $cfg = $this->configProvider->forSalesChannel($salesChannelId);

        $this->logger->info('[Curbstone] Config loaded', [
            'enabled'             => $cfg->enabled,
            'sandbox'             => $cfg->sandbox,
            'customerId'          => $cfg->customerId,
            'merchantCode'        => $cfg->merchantCode,
            'authCaptureFlow'     => $cfg->authCaptureFlow,
            'plpMode'             => $cfg->plpMode,
            'checkoutIntegration' => $cfg->checkoutIntegration,
            'disableSubscribers'  => $cfg->disableSubscribers,
        ]);

        if (!$cfg->enabled) {
            return;
        }

        $fallback     = '@Curbstone/storefront/curbstone-payment/curbstone-payment.html.twig';
        $templatePath = $fallback;

        try {
            $resolved = $this->templateFinder->find($fallback);
            if (\is_string($resolved)) {
                $templatePath = $resolved;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Curbstone] Template resolution failed, using fallback path.', [
                'fallback' => $fallback,
                'error'    => $e->getMessage(),
            ]);
        }

        $mode = $cfg->sandbox ? 'sandbox' : 'live';

        $page->addExtension('curbstone_payment', new ArrayStruct([
            'mode'     => $mode,
            'handler'  => CreditCard::class,
            'template' => $templatePath,
        ]));

        if (!$customer) {
            $guestCards = [];

            if (\is_array($preauth ?? null)) {
                $raw    = $preauth['raw'] ?? [];
                $mfukey = $preauth['mfukey'] ?? null;

                if ($mfukey && \is_array($raw)) {
                    $brand = $raw['MFCARD']
                        ?? $raw['MFCARDTYP']
                        ?? 'Card';

                    $type = $raw['MFCARDTYP']
                        ?? $raw['MFCARDTYPE']
                        ?? $raw['MFRVNA']
                        ?? null;

                    if (\is_string($type)) {
                        $type = trim($type);

                        if ($type !== '') {
                            $normalized = strtoupper($type);
                            $code = strtoupper((string) preg_split('/[\/\s-]+/', $normalized, 2)[0]);

                            $type = match (true) {
                                str_starts_with($normalized, 'VISA'),
                                str_starts_with($code, 'VI') => 'Visa',
                                str_starts_with($normalized, 'MASTERCARD'),
                                str_starts_with($normalized, 'MC'),
                                str_starts_with($code, 'MC') => 'Mastercard',
                                str_starts_with($normalized, 'AMEX'),
                                str_starts_with($normalized, 'AMERICAN'),
                                str_starts_with($code, 'AX'),
                                str_starts_with($code, 'AM') => 'American Express',
                                str_starts_with($normalized, 'DISCOVER'),
                                str_starts_with($code, 'DI') => 'Discover',
                                default => ucfirst(strtolower($type)),
                            };
                        } else {
                            $type = null;
                        }
                    } else {
                        $type = null;
                    }

                    $masked = $raw['MFCARD'] ?? '';
                    $last4  = $raw['MFCRD4'] ?? ($masked ? substr($masked, -4) : '0000');

                    $expRaw  = $raw['MFEDAT'] ?? null;
                    $exp     = null;
                    if ($expRaw && \strlen($expRaw) >= 4) {
                        $mm = substr($expRaw, 0, 2);
                        $yy = substr($expRaw, 2, 2);
                        $exp = $mm . '/' . $yy;
                    }

                    $mfname = trim((string) ($raw['MFNAME'] ?? ''));
                    if ($mfname === '') {
                        $mfname = trim((string) ($preauth['submittedMfName'] ?? ''));
                    }
                    $holderData = $this->vaultService->resolveCardHolderData(
                        $raw,
                        null,
                        $mfname,
                        false
                    );

                    $guestCards[] = [
                        'mfkeyp'                => $mfukey,
                        'brand'                 => $brand,
                        'type'                  => $type,
                        'cardHolderFirstName'   => $holderData['firstName'],
                        'cardHolderLastName'    => $holderData['lastName'],
                        'cardHolderName'        => $holderData['fullName'],
                        'holderName'            => $holderData['fullName'],
                        'cardHolderNameVerified' => $holderData['verified'],
                        'last4'                 => $last4,
                        'exp'                   => $exp,
                    ];
                } else {
                    $this->logger->info('[Curbstone] Guest preauth has no mfukey/raw, cannot build virtual card.', [
                        'preauth' => $preauth,
                    ]);
                }
            }

            $page->addExtension('curbstone_saved_cards', new ArrayStruct($guestCards));

            return;
        }

        $savedCards = $this->vaultService->loadNormalizedSavedCards(
            $customer->getId(),
            $scContext->getContext()
        );

        $page->addExtension('curbstone_saved_cards', new ArrayStruct($savedCards));

        $this->logger->info('[Curbstone] Loaded saved cards for page', [
            'customerId' => $customer->getId(),
            'count'      => \count($savedCards),
            'cards'      => $savedCards,
        ]);
    }
}
