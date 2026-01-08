<?php

declare(strict_types=1);

namespace Curbstone\Mapper;

use Curbstone\Dto\AuthorizeRequest;
use Curbstone\Dto\AuthorizeResponse;

/**
 * @phpstan-type CurbstoneCreds array{
 *   dsiKey: non-empty-string,
 *   customerId: non-empty-string,
 *   merchantCode: non-empty-string
 * }
 * @phpstan-type CurbstonePayload array<string, non-empty-string>
 */
final class AuthorizeMapperImpl implements AuthorizeMapper
{
    /**
     * @param CurbstoneCreds $creds
     * @return CurbstonePayload
     */
    public function toPayload(AuthorizeRequest $req, array $creds): array
    {
        // Guaranteed non-empty by phpdoc type
        $dsiKey       = $creds['dsiKey'];
        $customerId   = $creds['customerId'];
        $merchantCode = $creds['merchantCode'];

        /** @var array<string,string> $payload */
        $payload = [
            'MFDSIT' => '1',
            'MFDSIK' => $dsiKey,
            'MFCUST' => $customerId,
            'MFMRCH' => $merchantCode,

            'MFTYPE' => 'RA',
            'MFTYP2' => 'PA',

            'MFAMT1' => $this->fmtAmountMinor($req->amountMinor),
            'MFCURR' => \strtoupper($req->currency),
            'MFREFR' => $this->limit($req->orderNumber, 40),

            'MFUSER' => 'SHOPWARE',
            'MFMETH' => '01',
        ];

        // Optional token
        if ($req->previousToken !== null && $req->previousToken !== '') {
            $payload['MFKEYP'] = $req->previousToken;
        }

        // Optional AVS (locally widen shape to include 'name')
        if (!empty($req->avs)) {
            /** @var array{street?:string, city?:string, state?:string, zip?:string, name?:string} $avs */
            $avs = $req->avs;

            $name   = $this->limit((string)($avs['name']   ?? ''), 40);
            $street = $this->limit((string)($avs['street'] ?? ''), 40);
            $city   = $this->limit((string)($avs['city']   ?? ''), 30);
            $state  = $this->limit((string)($avs['state']  ?? ''), 2);
            $zip    = $this->limit((string)($avs['zip']    ?? ''), 10);

            if ($name   !== '') {
                $payload['MFNAME'] = $name;
            }
            if ($street !== '') {
                $payload['MFADD1'] = $street;
            }
            if ($city   !== '') {
                $payload['MFCITY'] = $city;
            }
            if ($state  !== '') {
                $payload['MFSTAT'] = $state;
            }
            if ($zip    !== '') {
                $payload['MFZIPC'] = $zip;
            }
        }

        /** @var CurbstonePayload $payload */
        return $payload;
    }

    /**
     * @param array<string,mixed> $raw
     */
    public function fromResponse(array $raw): AuthorizeResponse
    {
        /** @var array<string,mixed> $R */
        $R = [];
        foreach ($raw as $k => $v) {
            $R[\strtoupper((string)$k)] = $v;
        }

        $code   = \strtoupper((string)($R['MFRTRN'] ?? ''));
        $status = match ($code) {
            'UG' => AuthorizeResponse::STATUS_APPROVED,
            'UN' => AuthorizeResponse::STATUS_DECLINED,
            'UL' => AuthorizeResponse::STATUS_ERROR,
            default => AuthorizeResponse::STATUS_ERROR,
        };

        $msg = (string)($R['MFRTXT'] ?? '');
        if ($status === AuthorizeResponse::STATUS_ERROR && !empty($R['MFATAL'])) {
            $msg = \trim($msg . ' ' . (string)$R['MFATAL']);
        }

        $networkRef = (string)($R['MFRREF'] ?? ($R['MFNREF'] ?? ''));

        return new AuthorizeResponse(
            status:     $status,
            token:      (string)($R['MFUKEY'] ?? ''),
            networkRef: $networkRef,
            message:    $msg,
            raw:        $raw
        );
    }

    private function fmtAmountMinor(int $amountMinor): string
    {
        return \number_format($amountMinor / 100, 2, '.', '');
    }

    private function limit(string $s, int $max): string
    {
        return \strlen($s) > $max ? \substr($s, 0, $max) : $s;
    }
}
