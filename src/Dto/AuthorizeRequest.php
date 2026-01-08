<?php

declare(strict_types=1);

namespace Curbstone\Dto;

/**
 * @phpstan-type AvsData array{
 *   street?: non-empty-string,
 *   city?: non-empty-string,
 *   state?: non-empty-string,
 *   zip?: non-empty-string
 * }
 *
 * @phpstan-type AuthorizeRequestPayload array{
 *   MFCUST?: non-empty-string|null,
 *   MFMRCH?: non-empty-string|null,
 *   MFTYPE: 'RA',
 *   MFTYP2: 'SA'|'PA',
 *   MFMETH: '02',
 *   MFORDR: non-empty-string,
 *   MFREFR: non-empty-string,
 *   MFADD1?: non-empty-string,
 *   MFCITY?: non-empty-string,
 *   MFSTAT?: non-empty-string,
 *   MFZIPC?: non-empty-string,
 *   MFDSTZ: non-empty-string,
 *   MFAMT1: int,
 *   MFUSER: non-empty-string,
 *   MPCUST?: non-empty-string|null,
 *   MPCUSF?: non-empty-string,
 *   MPTRGT?: non-empty-string|null
 * }
 */
readonly final class AuthorizeRequest
{
    public function __construct(
        public int $amountMinor,
        public string $currency,
        public string $orderNumber,
        public ?string $previousToken = null,
        /** @var AvsData|null */
        public ?array $avs = null,
        public ?string $salesChannelId = null,
        public bool $stub = false,
        public ?string $customerEmail = null,
        public ?string $customerNumber = null,
        public ?string $returnUrl = null,
        public ?string $merchantCode = null,
        public ?string $customerCode = null,
        public string $flow = 'auth_only',
    ) {
    }

    /**
     * @return AuthorizeRequestPayload
     */
    public function toPayload(string $orderTxId, string $dstZip): array
    {
        $street = $this->limit((string)($this->avs['street'] ?? ''), 40);
        $city   = $this->limit((string)($this->avs['city']   ?? ''), 30);
        $state  = $this->limit((string)($this->avs['state']  ?? ''), 10);
        $zip    = $this->limit((string)($this->avs['zip']    ?? ''), 10);

        /** @var AuthorizeRequestPayload $payload */
        $payload = [
            'MFCUST' => $this->customerCode,
            'MFMRCH' => $this->merchantCode,
            'MFTYPE' => 'RA',
            'MFTYP2' => ($this->flow === 'auth_capture') ? 'SA' : 'PA',
            'MFMETH' => '02',
            'MFORDR' => $this->orderNumber,
            'MFREFR' => $orderTxId,
            'MFADD1' => $street !== '' ? $street : null,
            'MFCITY' => $city   !== '' ? $city   : null,
            'MFSTAT' => $state  !== '' ? $state  : null,
            'MFZIPC' => $zip    !== '' ? $zip    : null,
            'MFDSTZ' => $this->limit($dstZip, 10),
            'MFAMT1' => $this->amountMinor,
            'MFUSER' => $this->customerEmail !== null && $this->customerEmail !== '' ? $this->customerEmail : 'guest',
            'MPCUST' => $this->customerCode,
            'MPCUSF' => $this->customerNumber ?? '',
            'MPTRGT' => $this->returnUrl,
        ];

        return array_filter(
            $payload,
            static fn($v) => $v !== null
        );
    }

    private function limit(string $s, int $max): string
    {
        return \strlen($s) > $max ? \substr($s, 0, $max) : $s;
    }
}
