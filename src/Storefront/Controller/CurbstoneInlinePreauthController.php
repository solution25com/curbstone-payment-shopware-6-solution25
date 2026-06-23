<?php declare(strict_types=1);

namespace Curbstone\Storefront\Controller;

use Curbstone\Config\CurbstoneConfigProvider;
use Curbstone\Service\CurbstonePreauthClient;
use Curbstone\Service\CurbstoneRequestFactory;
use Curbstone\Service\CurbstoneVaultService;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class CurbstoneInlinePreauthController extends StorefrontController
{
    public function __construct(
        private readonly CurbstoneConfigProvider $configProvider,
        private readonly LoggerInterface         $logger,
        private readonly CurbstoneRequestFactory $requestFactory,
        private readonly CurbstonePreauthClient  $preauthClient,
        private readonly CurbstoneVaultService   $vaultService,
        private readonly string                  $kernelEnvironment,
    ) {}

    /**
     * Checkout 0.00 preauth – used on confirm page.
     */
    #[Route(
        path: '/curbstone/inline/preauth',
        name: 'frontend.curbstone.inline.preauth',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true, '_csrf_protected' => false]
    )]
    public function preauth(Request $request, SalesChannelContext $ctx): JsonResponse
    {
        $scId     = $ctx->getSalesChannel()->getId();
        $cfg      = $this->configProvider->forSalesChannel($scId);
        $ctxToken = $ctx->getToken();

        $saveCardParam = (string) $request->request->get('saveCard', '0');
        $from          = trim((string) $request->request->get('from', 'checkout'));
        $orderId       = trim((string) $request->request->get('orderId', ''));
        $referer       = (string) $request->headers->get('referer', '');

        // Backend safety net: if storefront JS is stale and still posts `from=checkout`,
        // infer account-edit flow from referer URL so return routing stays correct.
        if (($from === '' || $from === 'checkout') && preg_match('~/account/order/edit/([^/?#]+)~', $referer, $m) === 1) {
            $from = 'account_edit';
            if ($orderId === '') {
                $orderId = (string) ($m[1] ?? '');
            }
        }

        $session       = $request->getSession();
        $saveCardKey = 'curbstone_save_card_' . $ctxToken;

        $session->set($saveCardKey, $saveCardParam);

        $this->logger->info('[Curbstone] PREAUTH: endpoint hit', [
            'ctxToken'      => $ctxToken,
            'salesChannelId'=> $scId,
            'saveCardParam' => $saveCardParam,
            'from'          => $from,
            'orderId'       => $orderId,
            'referer'       => $referer,
            'requestParams' => $request->request->all(),
        ]);

        if (!$cfg->customerId || !$cfg->merchantCode) {
            $this->logger->error('[Curbstone] PREAUTH ERROR: Missing config');
            return $this->errorJson('Curbstone config missing.', 400);
        }

        $portal = $this->configProvider->plpBaseUrl($cfg->sandbox);

        $returnParams = ['from' => $from];
        if ($orderId !== '') {
            $returnParams['orderId'] = $orderId;
        }

        $returnUrl = $this->generateUrl(
            'frontend.curbstone.inline.preauth.return',
            $returnParams,
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $cust   = $ctx->getCustomer();
        $mpcusf = $cust?->getCustomerNumber() ?? ($cust?->getEmail() ?? 'guest');
        $addr   = $this->resolveAddress($ctx);

        if (!$addr) {
            $this->logger->error('[Curbstone] PREAUTH ERROR: No address in context');
            return $this->errorJson('No customer address found.', 400);
        }

        $mfname = $this->resolveDebugMfNameOverride() ?? '';
        $mfnameSource = $mfname !== '' ? 'debug_override' : 'default_empty';

        $this->logger->info('[Curbstone] PREAUTH MFNAME resolution', [
            'ctxToken' => $ctxToken,
            'kernelEnvironment' => $this->kernelEnvironment,
            'overrideEnvPresent' => array_key_exists('CURBSTONE_DEBUG_MFNAME', $_ENV),
            'overrideEnvLength' => \strlen((string) ($_ENV['CURBSTONE_DEBUG_MFNAME'] ?? '')),
            'resolvedMfName' => $mfname,
            'resolvedMfNameSource' => $mfnameSource,
        ]);

        $body = $this->requestFactory->buildPreauthBody(
            $cfg,
            $ctxToken,
            $addr,
            $mpcusf,
            $returnUrl,
            $mfname
        );

        $this->logger->info('[Curbstone] PREAUTH outbound payload', [
            'ctxToken' => $ctxToken,
            'MFNAME' => $mfname,
            'MFNAME_SOURCE' => $mfnameSource,
            'payload' => $body,
        ]);

        try {
            $result = $this->preauthClient->sendPreauthInit($portal, $body, $cfg->verifyTls);
        } catch (\Throwable $e) {
            $this->logger->error('[Curbstone] PREAUTH HTTP error', [
                'message' => $e->getMessage(),
            ]);
            return $this->errorJson('Curbstone 0-auth HTTP error.', 500);
        }

        if (empty($result['MFSESS'])) {
            $this->logger->error('[Curbstone] PREAUTH ERROR: Missing MFSESS', [
                'response' => $result,
            ]);
            return $this->errorJson('Curbstone init failed (MFSESS missing).', 500);
        }

        return new JsonResponse([
            'success'   => true,
            'iframeUrl' => $portal . '?MFSESS=' . rawurlencode($result['MFSESS']) . '&mode=embedded',
            'mfSess'    => $result['MFSESS'],
        ]);
    }

    /**
     * Account 0.00 preauth – for adding a saved card from /account/saved-cards
     */
    #[Route(
        path: '/curbstone/inline/preauth/account',
        name: 'frontend.curbstone.inline.preauth.account',
        methods: ['POST'],
        defaults: ['XmlHttpRequest' => true, '_csrf_protected' => false]
    )]
    public function preauthAccount(Request $request, SalesChannelContext $ctx): JsonResponse
    {
        $scId     = $ctx->getSalesChannel()->getId();
        $cfg      = $this->configProvider->forSalesChannel($scId);
        $ctxToken = $ctx->getToken();

        $session       = $request->getSession();
        $saveCardKey = 'curbstone_save_card_' . $ctxToken;

        // On account page, always save the card
        $session->set($saveCardKey, '1');

        $this->logger->info('[Curbstone] ACCOUNT PREAUTH: endpoint hit', [
            'ctxToken'      => $ctxToken,
            'salesChannelId'=> $scId,
        ]);

        if (!$cfg->customerId || !$cfg->merchantCode) {
            $this->logger->error('[Curbstone] ACCOUNT PREAUTH ERROR: Missing config');
            return $this->errorJson('Curbstone config missing.', 400);
        }

        $portal = $this->configProvider->plpBaseUrl($cfg->sandbox);

        // Mark this as coming from account page
        $returnUrl = $this->generateUrl(
            'frontend.curbstone.inline.preauth.return',
            ['from' => 'account'],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $cust = $ctx->getCustomer();
        if (!$cust) {
            $this->logger->error('[Curbstone] ACCOUNT PREAUTH ERROR: No logged-in customer');
            return $this->errorJson('No logged-in customer.', 400);
        }

        $mpcusf = $cust->getCustomerNumber() ?? ($cust->getEmail() ?? 'account');

        $addr = $this->resolveAddress($ctx);
        if (!$addr) {
            $this->logger->error('[Curbstone] ACCOUNT PREAUTH ERROR: No address in context');
            return $this->errorJson('No customer address found.', 400);
        }

        $mfname = $this->resolveDebugMfNameOverride() ?? '';
        $mfnameSource = $mfname !== '' ? 'debug_override' : 'default_empty';

        $this->logger->info('[Curbstone] ACCOUNT PREAUTH MFNAME resolution', [
            'ctxToken' => $ctxToken,
            'kernelEnvironment' => $this->kernelEnvironment,
            'overrideEnvPresent' => array_key_exists('CURBSTONE_DEBUG_MFNAME', $_ENV),
            'overrideEnvLength' => \strlen((string) ($_ENV['CURBSTONE_DEBUG_MFNAME'] ?? '')),
            'resolvedMfName' => $mfname,
            'resolvedMfNameSource' => $mfnameSource,
        ]);

        $body = $this->requestFactory->buildPreauthBody(
            $cfg,
            $ctxToken,
            $addr,
            $mpcusf,
            $returnUrl,
            $mfname
        );

        $this->logger->info('[Curbstone] ACCOUNT PREAUTH outbound payload', [
            'ctxToken' => $ctxToken,
            'MFNAME' => $mfname,
            'MFNAME_SOURCE' => $mfnameSource,
            'payload' => $body,
        ]);

        try {
            $result = $this->preauthClient->sendPreauthInit($portal, $body, $cfg->verifyTls);
        } catch (\Throwable $e) {
            $this->logger->error('[Curbstone] ACCOUNT PREAUTH HTTP error', [
                'message' => $e->getMessage(),
            ]);
            return $this->errorJson('Curbstone 0-auth HTTP error.', 500);
        }

        if (empty($result['MFSESS'])) {
            $this->logger->error('[Curbstone] ACCOUNT PREAUTH ERROR: Missing MFSESS', [
                'response' => $result,
            ]);
            return $this->errorJson('Curbstone init failed (MFSESS missing).', 500);
        }

        return new JsonResponse([
            'success'   => true,
            'iframeUrl' => $portal . '?MFSESS=' . rawurlencode($result['MFSESS']) . '&mode=embedded',
            'mfSess'    => $result['MFSESS'],
        ]);
    }

    #[Route(
        path: '/curbstone/inline/preauth/return',
        name: 'frontend.curbstone.inline.preauth.return',
        methods: ['POST'],
        defaults: ['_csrf_protected' => false]
    )]
    public function preauthReturn(Request $request, SalesChannelContext $ctx): Response
    {
        $fields = $request->request->all();

        $this->logger->info('[Curbstone] MPTRGT RETURN HIT', [
            'rawFields' => $fields,
        ]);

        $mfrtrn = strtoupper(trim((string) ($fields['MFRTRN'] ?? '')));
        $mfukey = $fields['MFUKEY'] ?? null;
        $returnedMfName = trim((string) ($fields['MFNAME'] ?? ''));

        $avs = $fields['MFRAVS'] ?? null;
        $cvv = $fields['MFRCVV'] ?? null;

        $isOk   = ($mfrtrn === 'UG');
        $status = $isOk ? 'OK' : 'FAIL';

        $ctxToken    = $ctx->getToken();
        $sessionKey  = 'curbstone_preauth_' . $ctxToken;
        $saveCardKey = 'curbstone_save_card_' . $ctxToken;

        $session = $request->getSession();
        $rawSaveCard = (string) $session->get($saveCardKey, '0');
        $payload = [
            'status' => $status,
            'token'  => $mfrtrn,
            'mfukey' => $mfukey,
            'raw'    => $fields,
            'submittedMfName' => $this->resolveDebugMfNameOverride() ?? '',
            'ctxToken' => $ctxToken,
            'createdAt' => time(),
        ];

        $this->logger->info('[Curbstone] MPTRGT PREAUTH PAYLOAD', [
            'sessionKey' => $sessionKey,
            'payload'    => $payload,
            'sessionStoredSubmittedMfName' => $payload['submittedMfName'],
            'approvalCheck' => [
                'isOk' => $isOk,
                'mfrtrn' => $mfrtrn,
                'avs' => $avs,
                'cvv' => $cvv,
                'rule' => 'MFRTRN==UG',
            ],
        ]);

        $session->set($sessionKey, $payload);
        $session->set('curbstone_preauth_latest', [
            'key' => $sessionKey,
            'ctxToken' => $ctxToken,
            'createdAt' => $payload['createdAt'],
        ]);

        $session->remove($saveCardKey);

        $saveCard = ($rawSaveCard === '1');

        $this->logger->info('[Curbstone] MPTRGT STORED IN SESSION', [
            'sessionKey'      => $sessionKey,
            'payload'         => $payload,
            'latestPointer'   => $session->get('curbstone_preauth_latest'),
            'rawSaveCard'     => $rawSaveCard,
            'saveCardBoolean' => $saveCard,
        ]);

        $customer = $ctx->getCustomer();

        if ($status === 'OK' && $mfukey && $customer && $saveCard) {
            try {
                $resolvedMfName = $returnedMfName;
                if ($resolvedMfName === '') {
                    $submittedMfName = trim((string) ($payload['submittedMfName'] ?? ''));
                    if ($submittedMfName !== '') {
                        $resolvedMfName = $submittedMfName;
                    }
                }

                $holderData = $this->vaultService->resolveCardHolderData(
                    $fields,
                    null,
                    $resolvedMfName,
                    false
                );
                $billingAddressId = null;
                $last4 = trim((string) ($fields['MFCRD4'] ?? ''));
                if ($last4 === '') {
                    $rawCard = preg_replace('/\D/', '', (string) ($fields['MFCARD'] ?? ''));
                    $last4 = \strlen((string) $rawCard) >= 4 ? substr((string) $rawCard, -4) : '';
                }

                $maskedCard = $last4 !== '' ? '**** **** **** ' . $last4 : '****';
                $expiry = $this->resolveExpiry((string) ($fields['MFEXP1'] ?? ''), (string) ($fields['MFEXP2'] ?? ''), (string) ($fields['MFEDAT'] ?? ''), (string) ($fields['MFEXP'] ?? ''));
                $cardVendor = trim((string) ($fields['MFRVNA'] ?? ''));
                $cardType = trim((string) ($fields['MFCARDTYP'] ?? $fields['MFMETH'] ?? ''));
                $mfnameState = $returnedMfName === '' ? 'empty' : 'populated';
                $resolvedMfNameState = $resolvedMfName === '' ? 'empty' : 'populated';

                $this->logger->info('[Curbstone] Preparing vaulted card save', [
                    'customerId' => $customer->getId(),
                    'billingAddressId' => $billingAddressId,
                    'returnedMfName' => $returnedMfName,
                    'mfnameState' => $mfnameState,
                    'resolvedMfName' => $resolvedMfName,
                    'resolvedMfnameState' => $resolvedMfNameState,
                    'sessionStoredSubmittedMfName' => trim((string) ($payload['submittedMfName'] ?? '')),
                    'mfukey' => $mfukey,
                    'maskedCard' => $maskedCard,
                    'last4' => $last4,
                    'expiry' => $expiry,
                    'cardVendor' => $cardVendor,
                    'cardType' => $cardType,
                ]);

                $this->vaultService->storeVaultedCard(
                    $customer->getId(),
                    $fields,
                    $ctx->getContext(),
                    $holderData
                );
            } catch (\Throwable $e) {
                $this->logger->error('[Curbstone] Failed to save vaulted card', [
                    'customerId' => $customer->getId(),
                    'exception'  => $e->getMessage(),
                ]);
            }
        } else {
            $this->logger->info('[Curbstone] Vaulted card NOT saved', [
                'status'      => $status,
                'hasMfukey'   => (bool) $mfukey,
                'hasCustomer' => (bool) $customer,
                'saveCard'    => $saveCard,
            ]);
        }

        // Decide where to redirect after preauth
        $from = (string) $request->query->get('from', 'checkout');

        if ($from === 'account') {
            return $this->redirectToRoute('frontend.account.saved_cards.page');
        }

        if ($from === 'account_edit') {
            $orderId = trim((string) $request->query->get('orderId', ''));
            if ($orderId !== '') {
                return $this->redirectToRoute('frontend.account.edit-order.page', [
                    'orderId' => $orderId,
                    'curbstone_auto' => 1,
                    'curbstone_status' => $status,
                    'curbstone_token' => (string) $mfrtrn,
                    'curbstone_mfukey' => (string) $mfukey,
                ]);
            }
        }

        // default: checkout confirm with auto-submit flag
        return $this->redirectToRoute('frontend.checkout.confirm.page', [
            'curbstone_auto' => 1,
            'curbstone_status' => $status,
            'curbstone_token' => (string) $mfrtrn,
            'curbstone_mfukey' => (string) $mfukey,
        ]);
    }

    private function resolveAddress(SalesChannelContext $ctx): ?array
    {
        $addr = $ctx->getShippingLocation()?->getAddress()
            ?? $ctx->getCustomer()?->getDefaultBillingAddress();

        return $addr ? [
            'addr1'  => trim(($addr->getStreet() ?? '') . ' ' . ($addr->getAdditionalAddressLine1() ?? '')),
            'city'   => (string) $addr->getCity(),
            'state'  => (string) ($addr->getCountryState()?->getShortCode() ?? ''),
            'zip'    => (string) $addr->getZipcode(),
            'dstZip' => (string) $addr->getZipcode(),
        ] : null;
    }

    private function errorJson(string $message, int $code): JsonResponse
    {
        return new JsonResponse(['success' => false, 'error' => $message], $code);
    }

    private function resolveExpiry(string $expMonth, string $expYear, string $mfedat, string $mfexp): string
    {
        $mm = trim($expMonth);
        $yy = trim($expYear);

        if ($mm !== '' && $yy !== '') {
            return sprintf('%02d/%s', (int) $mm, substr($yy, -2));
        }

        if ($mfedat !== '' && \strlen($mfedat) >= 4) {
            return substr($mfedat, 0, 2) . '/' . substr($mfedat, 2, 2);
        }

        $clean = preg_replace('/[^0-9]/', '', $mfexp);
        if (\strlen((string) $clean) >= 4) {
            return substr((string) $clean, 0, 2) . '/' . substr((string) $clean, 2, 2);
        }

        return '';
    }

    private function resolveDebugMfNameOverride(): ?string
    {
        if (!\in_array($this->kernelEnvironment, ['dev', 'test'], true)) {
            return null;
        }

        $override = trim((string) ($_ENV['CURBSTONE_DEBUG_MFNAME'] ?? \getenv('CURBSTONE_DEBUG_MFNAME') ?? ''));

        $this->logger->info('[Curbstone] Debug MFNAME override check', [
            'kernelEnvironment' => $this->kernelEnvironment,
            'overridePresent' => $override !== '',
            'overrideLength' => \strlen($override),
        ]);

        return $override !== '' ? $override : null;
    }
}
