<?php declare(strict_types=1);

namespace Curbstone\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;

final class CurbstoneCardResolver
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CurbstoneVaultService $vaultService,
    ) {}

    public function resolve(Request $request, string $txId, ?string $orderCustomerId, Context $context): array
    {
        $choice = trim((string) ($request->request->get('curbstone_card_choice') ?? ''));

        $usingSavedCard = ($choice !== '' && $choice !== 'new');

        if ($usingSavedCard) {
            if ($orderCustomerId === null || $orderCustomerId === '') {
                $this->logger->error('Curbstone: saved card selected but order has no customer id', [
                    'txId' => $txId,
                ]);
                throw new \RuntimeException('Curbstone: saved card requires a logged-in customer.');
            }

            $mfkeypFromForm = $this->vaultService->resolveOwnedMfkeyp($orderCustomerId, $choice, $context);

            $this->logger->info('Curbstone: Using verified saved card (MFKEYP) for payment', [
                'txId'   => $txId,
                'mfkeyp' => $mfkeypFromForm,
            ]);

            return [
                'mfkeyp'         => $mfkeypFromForm,
                'token'          => null,
                'mfukey'         => null,
                'usingSavedCard' => true,
            ];
        }

        $session = $request->getSession();

        $ctxToken = trim((string) ($request->request->get('curbstone_ctx_token') ?? ''));

        if ($ctxToken === '') {
            $cookieToken = (string) ($request->cookies->get('sw-context-token') ?? '');
            $headerToken = (string) ($request->headers->get('sw-context-token') ?? '');

            $ctxToken = $cookieToken !== '' ? $cookieToken : $headerToken;
        }

        $sessionKey = $ctxToken !== '' ? ('curbstone_preauth_' . $ctxToken) : null;
        $preauth    = null;

        if ($sessionKey !== null) {
            $preauth = $session->get($sessionKey);
        }

        if (!\is_array($preauth)) {
            $latest = $session->get('curbstone_preauth_latest');
            if (\is_array($latest) && isset($latest['key']) && \is_string($latest['key'])) {
                $latestKey = trim($latest['key']);
                if ($latestKey !== '') {
                    $candidate = $session->get($latestKey);
                    if (\is_array($candidate)) {
                        $sessionKey = $latestKey;
                        $preauth = $candidate;
                    }
                }
            }
        }

        if (!\is_array($preauth)) {
            $fallbackKey = null;

            foreach ($session->all() as $key => $value) {
                if (\is_string($key)
                    && str_starts_with($key, 'curbstone_preauth_')
                    && \is_array($value)
                ) {
                    $fallbackKey = $key;
                    $preauth     = $value;
                    break;
                }
            }

            if ($fallbackKey !== null) {
                $sessionKey = $fallbackKey;
            }
        }

        $this->logger->info('Curbstone: session preauth payload on pay()', [
            'sessionKey' => $sessionKey,
            'ctxToken'   => $ctxToken,
            'latestPreauthPointer' => $session->get('curbstone_preauth_latest'),
            'hasPreauth' => \is_array($preauth),
            'postedStatus' => $request->request->get('curbstone_pre_auth_status'),
            'postedToken'  => $request->request->get('curbstone_pre_auth_token'),
            'postedMfukey' => $request->request->get('curbstone_pre_auth_mfukey'),
        ]);

        if (!\is_array($preauth)) {
            $postedStatus = strtoupper(trim((string) ($request->request->get('curbstone_pre_auth_status') ?? '')));
            $postedToken = trim((string) ($request->request->get('curbstone_pre_auth_token') ?? ''));
            $postedMfukey = trim((string) ($request->request->get('curbstone_pre_auth_mfukey') ?? ''));

            if ($postedStatus === 'OK' && $postedToken !== '' && $postedMfukey !== '') {
                $this->logger->warning('Curbstone: falling back to posted preauth payload (session missing)', [
                    'txId' => $txId,
                    'ctxToken' => $ctxToken,
                    'postedStatus' => $postedStatus,
                    'postedToken' => $postedToken,
                    'postedMfukey' => $postedMfukey,
                ]);

                return [
                    'mfkeyp'         => $postedMfukey,
                    'token'          => $postedToken,
                    'mfukey'         => $postedMfukey,
                    'usingSavedCard' => false,
                ];
            }

            $this->logger->error('Curbstone: no preauth data in session for NEW CARD', [
                'sessionKey' => $sessionKey,
                'ctxToken'   => $ctxToken,
                'sessionKeys'=> array_keys($session->all()),
            ]);

            throw new \RuntimeException('Curbstone: 0.00 auth data missing – iframe/preauth step failed.');
        }

        if (($preauth['status'] ?? '') !== 'OK') {
            $this->logger->error('Curbstone: preauth status is not OK for NEW CARD', [
                'payload' => $preauth,
            ]);
            throw new \RuntimeException('Curbstone: 0.00 auth failed – card was not approved.');
        }

        if (empty($preauth['token']) || empty($preauth['mfukey'])) {
            $this->logger->error('Curbstone: preauth token or MFUKEY missing for NEW CARD', [
                'payload' => $preauth,
            ]);
            throw new \RuntimeException('Curbstone: 0.00 auth not completed or MFUKEY missing.');
        }

        $token  = (string) $preauth['token'];
        $mfukey = (string) $preauth['mfukey'];

        $mfkeyp = $mfukey;

        $this->logger->info('Curbstone: preauth accepted for NEW CARD, using MFKEYP from MFUKEY', [
            'txId'   => $txId,
            'token'  => $token,
            'mfukey' => $mfukey,
            'mfkeyp' => $mfkeyp,
        ]);

        return [
            'mfkeyp'         => $mfkeyp,
            'token'          => $token,
            'mfukey'         => $mfukey,
            'usingSavedCard' => false,
        ];
    }
}
