<?php declare(strict_types=1);

namespace Curbstone\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\Context as FrameworkContext;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

final class CurbstoneVaultService
{
    public function __construct(
        private readonly EntityRepository $customerRepository,
        private readonly LoggerInterface  $logger,
    ) {}

    public function storeVaultedCard(string $customerId, array $fields, FrameworkContext $context, ?array $holderData = null): void
    {
        $mfukey = $fields['MFUKEY'] ?? null;
        if (!$mfukey) {
            $this->logger->warning('[Curbstone] storeVaultedCard called without MFUKEY', [
                'fieldsKeys' => array_keys($fields),
            ]);
            return;
        }

        $brand = $this->resolveBrand($fields);
        $type  = $this->resolveCardType($fields);

        $last4 = $fields['MFCRD4'] ?? null;

        if (empty($last4)) {
            $rawCard = (string) ($fields['MFCARD'] ?? '');
            if ($rawCard !== '') {
                $digits = preg_replace('/\D/', '', $rawCard);
                if (\strlen($digits) >= 4) {
                    $last4 = substr($digits, -4);
                }
            }
        }

        if (empty($last4)) {
            $last4 = '0000';
        }

        $exp = null;

        $expMonth = $fields['MFEXP1'] ?? null;
        $expYear  = $fields['MFEXP2'] ?? null;

        if (!empty($expMonth) && !empty($expYear)) {
            $mm = (int) $expMonth;
            $yy = substr((string) $expYear, -2);
            if ($mm >= 1 && $mm <= 12) {
                $exp = sprintf('%02d/%s', $mm, $yy);
            }
        }

        if ($exp === null && !empty($fields['MFEDAT']) && \strlen((string) $fields['MFEDAT']) >= 4) {
            $raw = (string) $fields['MFEDAT']; // MMYY
            $mm  = (int) substr($raw, 0, 2);
            $yy  = substr($raw, 2, 2);
            if ($mm >= 1 && $mm <= 12) {
                $exp = sprintf('%02d/%s', $mm, $yy);
            }
        }

        if ($exp === null && !empty($fields['MFEXP'])) {
            $raw = preg_replace('/[^0-9]/', '', (string) $fields['MFEXP']);
            if (\strlen($raw) >= 4) {
                $mm = (int) substr($raw, 0, 2);
                $yy = substr($raw, 2, 2);
                if ($mm >= 1 && $mm <= 12) {
                    $exp = sprintf('%02d/%s', $mm, $yy);
                }
            }
        }

        if ($exp === null) {
            $this->logger->warning('[Curbstone] Could not derive expiry for vaulted card', [
                'MFEXP1' => $fields['MFEXP1'] ?? null,
                'MFEXP2' => $fields['MFEXP2'] ?? null,
                'MFEDAT' => $fields['MFEDAT'] ?? null,
                'MFEXP'  => $fields['MFEXP'] ?? null,
            ]);
        }

        $criteria = new Criteria([$customerId]);
        $customer = $this->customerRepository->search($criteria, $context)->first();

        if (!$customer) {
            $this->logger->warning('[Curbstone] Customer not found while saving vaulted card', [
                'customerId' => $customerId,
            ]);
            return;
        }

        $customFields = $customer->getCustomFields() ?? [];
        $savedCards   = $customFields['curbstone_saved_cards'] ?? [];
        $holderData   = [
            'firstName' => trim((string) ($holderData['firstName'] ?? '')),
            'lastName'  => trim((string) ($holderData['lastName'] ?? '')),
            'fullName'  => trim((string) ($holderData['fullName'] ?? '')),
            'verified'  => (bool) ($holderData['verified'] ?? false),
        ];

        if (!\is_array($savedCards)) {
            $this->logger->warning('[Curbstone] curbstone_saved_cards is not array, resetting', [
                'type'  => \gettype($savedCards),
                'value' => $savedCards,
            ]);
            $savedCards = [];
        }

        $duplicateIndex = null;
        foreach ($savedCards as $index => $existing) {
            if (!\is_array($existing)) {
                continue;
            }

            $sameMfkeyp = ($existing['mfkeyp'] ?? null) === $mfukey;
            $sameBrand = isset($existing['brand'], $brand) && $existing['brand'] === $brand;
            $sameLast4 = isset($existing['last4']) && $existing['last4'] === $last4;
            $sameExp   = isset($existing['exp'], $exp) && $existing['exp'] === $exp;

            if ($sameMfkeyp || ($sameBrand && $sameLast4 && $sameExp)) {
                $duplicateIndex = $index;
                break;
            }
        }

        if ($duplicateIndex !== null) {
            $existingCard = $savedCards[$duplicateIndex];
            if (\is_array($existingCard)) {
                $updatedCard = $this->applyCardHolderData($existingCard, $holderData);

                $isChanged = $updatedCard !== $existingCard;
                $savedCards[$duplicateIndex] = $updatedCard;

                if ($isChanged) {
                    $customFields['curbstone_saved_cards'] = $savedCards;
                    $this->customerRepository->update([[
                        'id' => $customerId,
                        'customFields' => $customFields,
                    ]], $context);

                    $this->logger->info('[Curbstone] Updated vaulted card holder metadata on duplicate save', [
                        'customerId' => $customerId,
                        'mfkeyp'     => $mfukey,
                        'brand'      => $brand,
                        'type'       => $type,
                        'last4'      => $last4,
                        'exp'        => $exp,
                    ]);
                } else {
                    $this->logger->info('[Curbstone] Vaulted card already stored, duplicate save ignored', [
                        'customerId' => $customerId,
                        'mfkeyp'     => $mfukey,
                        'brand'      => $brand,
                        'type'       => $type,
                        'last4'      => $last4,
                        'exp'        => $exp,
                    ]);
                }

                return;
            }
        }

        $savedCards[] = [
            'mfkeyp'               => $mfukey,
            'brand'                => $brand,
            'type'                 => $type,
            'cardHolderFirstName'  => $holderData['firstName'],
            'cardHolderLastName'   => $holderData['lastName'],
            'cardHolderName'       => $holderData['fullName'],
            'holderName'           => $holderData['fullName'],
            'cardHolderNameVerified' => $holderData['verified'],
            'last4'                => $last4,
            'exp'                  => $exp,
        ];

        $customFields['curbstone_saved_cards'] = $savedCards;

        $this->customerRepository->update([[
            'id'           => $customerId,
            'customFields' => $customFields,
        ]], $context);

        $this->logger->info('[Curbstone] Vaulted card saved on customer', [
            'customerId' => $customerId,
            'cards'      => $savedCards,
        ]);
    }

    public function deleteVaultedCard(string $customerId, string $mfkeyp, FrameworkContext $context): void
    {
        $mfkeyp = trim($mfkeyp);

        if ($mfkeyp === '') {
            $this->logger->warning('[Curbstone] deleteVaultedCard called with empty mfkeyp', [
                'customerId' => $customerId,
            ]);
            return;
        }

        $criteria = new Criteria([$customerId]);
        $customer = $this->customerRepository->search($criteria, $context)->first();

        if (!$customer) {
            $this->logger->warning('[Curbstone] Customer not found while deleting vaulted card', [
                'customerId' => $customerId,
                'mfkeyp'     => $mfkeyp,
            ]);
            return;
        }

        $customFields = $customer->getCustomFields() ?? [];
        $savedCards   = $customFields['curbstone_saved_cards'] ?? [];

        if (!\is_array($savedCards) || $savedCards === []) {
            $this->logger->info('[Curbstone] No saved cards found while deleting', [
                'customerId' => $customerId,
                'mfkeyp'     => $mfkeyp,
            ]);
            return;
        }

        $beforeCount = \count($savedCards);

        $savedCards = array_values(array_filter($savedCards, static function ($card) use ($mfkeyp) {
            return ($card['mfkeyp'] ?? null) !== $mfkeyp;
        }));

        $afterCount = \count($savedCards);

        if ($afterCount === $beforeCount) {
            $this->logger->info('[Curbstone] No vaulted card matched mfkeyp during delete', [
                'customerId' => $customerId,
                'mfkeyp'     => $mfkeyp,
            ]);
            return;
        }

        $customFields['curbstone_saved_cards'] = $savedCards;

        $this->customerRepository->update([[
            'id'           => $customerId,
            'customFields' => $customFields,
        ]], $context);

        $this->logger->info('[Curbstone] Vaulted card deleted', [
            'customerId'   => $customerId,
            'mfkeyp'       => $mfkeyp,
            'beforeCount'  => $beforeCount,
            'afterCount'   => $afterCount,
        ]);
    }

    /**
     * Load and normalize saved cards from the customer record.
     *
     * If older saved cards only have a generic or missing type, this backfills
     * the display fields in-place so storefront templates can render them
     * consistently without waiting for a new vault event.
     *
     * @return array<int, array<string, mixed>>
     */
    public function loadNormalizedSavedCards(string $customerId, FrameworkContext $context): array
    {
        $criteria = new Criteria([$customerId]);
        $customer = $this->customerRepository->search($criteria, $context)->first();

        if (!$customer) {
            $this->logger->warning('[Curbstone] Customer not found while loading saved cards', [
                'customerId' => $customerId,
            ]);

            return [];
        }

        $customFields = $customer->getCustomFields() ?? [];
        $savedCards   = $customFields['curbstone_saved_cards'] ?? [];

        if (!\is_array($savedCards)) {
            $this->logger->warning('[Curbstone] curbstone_saved_cards is not array while loading', [
                'customerId' => $customerId,
                'type'       => \gettype($savedCards),
                'value'      => $savedCards,
            ]);

            return [];
        }

        $normalizedCards = $this->normalizeSavedCards($savedCards, $customer, false);

        if ($normalizedCards !== $savedCards) {
            $customFields['curbstone_saved_cards'] = $normalizedCards;

            $this->customerRepository->update([[
                'id'           => $customerId,
                'customFields' => $customFields,
            ]], $context);

            $this->logger->info('[Curbstone] Backfilled saved card metadata on customer load', [
                'customerId' => $customerId,
                'beforeCount' => \count($savedCards),
                'afterCount'  => \count($normalizedCards),
            ]);
        }

        return $normalizedCards;
    }

    /**
     * @param array<int, array<string, mixed>> $savedCards
     * @return array<int, array<string, mixed>>
     */
    public function normalizeSavedCards(array $savedCards, ?CustomerEntity $customer = null, bool $useCustomerFallback = true): array
    {
        $normalizedCards = [];

        foreach ($savedCards as $card) {
            if (!\is_array($card)) {
                continue;
            }

            $normalizedCards[] = $this->normalizeSavedCard($card, $customer, $useCustomerFallback);
        }

        return $normalizedCards;
    }

    /**
     * Returns the canonical MFKEYP from the customer's vault when the posted value matches a saved card.
     * Rejects tokens that are not present on this customer's saved-card list (server-side ownership).
     */
    public function resolveOwnedMfkeyp(string $customerId, string $postedMfkeyp, FrameworkContext $context): string
    {
        $postedMfkeyp = trim($postedMfkeyp);

        if ($postedMfkeyp === '') {
            throw new \RuntimeException('Curbstone: saved card token is empty.');
        }

        $criteria = new Criteria([$customerId]);
        $customer = $this->customerRepository->search($criteria, $context)->first();

        if (!$customer) {
            $this->logger->warning('[Curbstone] Customer not found while resolving owned MFKEYP', [
                'customerId' => $customerId,
            ]);
            throw new \RuntimeException('Curbstone: customer not found for saved card verification.');
        }

        $customFields = $customer->getCustomFields() ?? [];
        $savedCards   = $customFields['curbstone_saved_cards'] ?? [];

        if (!\is_array($savedCards)) {
            $savedCards = [];
        }

        foreach ($savedCards as $card) {
            if (!\is_array($card)) {
                continue;
            }
            $stored = isset($card['mfkeyp']) ? trim((string) $card['mfkeyp']) : '';
            if ($stored !== '' && hash_equals($stored, $postedMfkeyp)) {
                return $stored;
            }
        }

        $this->logger->warning('[Curbstone] Posted MFKEYP does not match any saved card for customer', [
            'customerId' => $customerId,
        ]);

        throw new \RuntimeException('Curbstone: saved card is not valid for this customer.');
    }

    private function resolveBrand(array $fields): string
    {
        $type = $fields['MFCARDTYP'] ?? $fields['MFCARDTYPE'] ?? null;
    
        if (\is_string($type) && $type !== '') {
            $normalized = strtoupper(trim($type));
    
            return match (true) {
                str_contains($normalized, 'VISA')      => 'Visa',
                str_contains($normalized, 'MASTERCARD'),
                str_contains($normalized, 'MC')        => 'Mastercard',
                str_contains($normalized, 'AMEX'),
                str_contains($normalized, 'AMERICAN')  => 'American Express',
                str_contains($normalized, 'DISCOVER')  => 'Discover',
                default                                => $normalized,
            };
        }
    
        // Only try PAN-based detection if we have *enough* digits (e.g. full card/BIN)
        $rawCard = (string) ($fields['MFCARD'] ?? '');
        $clean   = preg_replace('/\D/', '', $rawCard);
    
        if (\strlen($clean) >= 6) { // at least BIN length, avoid "1111" junk
            $first2 = substr($clean, 0, 2);
            $first1 = substr($clean, 0, 1);
    
            if ($first1 === '4') {
                return 'Visa';
            }
    
            if ($first2 >= '51' && $first2 <= '55') {
                return 'Mastercard';
            }
    
            if ($first2 === '34' || $first2 === '37') {
                return 'American Express';
            }
    
            if ($first1 === '6') {
                return 'Discover';
            }
        }
    
        $this->logger->info('[Curbstone] Could not resolve card brand, falling back to "Card"', [
            'MFCARDTYP' => $fields['MFCARDTYP'] ?? null,
            'MFCARD'    => $fields['MFCARD'] ?? null,
        ]);
    
        return 'Card';
    }

    private function resolveCardType(array $fields): string
    {
        $rawType = $fields['MFCARDTYP'] ?? $fields['MFCARDTYPE'] ?? null;
        $rawCode = $fields['MFRVNA'] ?? null;

        $normalise = static function (string $value): string {
            return match (true) {
                str_contains($value, 'VISA')       => 'Visa',
                str_contains($value, 'MASTERCARD') => 'Mastercard',
                str_contains($value, 'MC')         => 'Mastercard',
                str_contains($value, 'AMEX')       => 'American Express',
                str_contains($value, 'AMERICAN')   => 'American Express',
                str_contains($value, 'DISCOVER')   => 'Discover',
                default                            => ucfirst(strtolower($value)),
            };
        };

        if (\is_string($rawType)) {
            $rawType = trim($rawType);

            if ($rawType !== '') {
                return $normalise(strtoupper($rawType));
            }
        }

        if (\is_string($rawCode)) {
            $rawCode = trim($rawCode);

            if ($rawCode !== '') {
                $code = strtoupper((string) preg_split('/[\/\s-]+/', $rawCode, 2)[0]);

                return match (true) {
                    str_starts_with($code, 'VI') => 'Visa',
                    str_starts_with($code, 'MC') => 'Mastercard',
                    str_starts_with($code, 'AX') => 'American Express',
                    str_starts_with($code, 'AM') => 'American Express',
                    str_starts_with($code, 'DI') => 'Discover',
                    default => $normalise(strtoupper($rawCode)),
                };
            }
        }

        return $this->resolveBrand($fields);
    }

    /**
     * @param array<string, mixed> $card
     * @return array<string, mixed>
     */
    private function normalizeSavedCard(array $card, ?CustomerEntity $customer = null, bool $useCustomerFallback = true): array
    {
        $type = trim((string) ($card['type'] ?? ''));
        $brand = trim((string) ($card['brand'] ?? ''));
        $holderData = [
            'firstName' => trim((string) ($card['cardHolderFirstName'] ?? '')),
            'lastName'  => trim((string) ($card['cardHolderLastName'] ?? '')),
            'fullName'  => trim((string) ($card['cardHolderName'] ?? ($card['holderName'] ?? ''))),
            'verified'  => (bool) ($card['cardHolderNameVerified'] ?? false),
        ];

        if ($type === '' && $brand !== '') {
            $type = $brand;
        }

        if ($brand === '' && $type !== '') {
            $brand = $type;
        }

        if ($type === '') {
            $type = 'Card';
        }

        if ($brand === '') {
            $brand = 'Card';
        }

        $card['type'] = $type;
        $card['brand'] = $brand;
        $card['cardHolderFirstName'] = $holderData['firstName'];
        $card['cardHolderLastName'] = $holderData['lastName'];
        $card['cardHolderName'] = $holderData['fullName'];
        $card['holderName'] = $holderData['fullName'];
        $card['cardHolderNameVerified'] = $holderData['verified'];

        return $card;
    }

    /**
     * @return array{firstName: string, lastName: string, fullName: string, verified: bool}
     */
    public function resolveCardHolderData(array $fields, ?CustomerEntity $customer = null, ?string $cardHolderName = null, bool $useCustomerFallback = true): array
    {
        $fullNameKeys = [
            'cardHolderName',
            'holderName',
        ];

        $cardHolderName = trim((string) $cardHolderName);
        $fullName = $this->extractFirstStringValue($fields, $fullNameKeys);
        $firstName = '';
        $lastName = '';

        if ($cardHolderName !== '') {
            $fullName = $cardHolderName;
        }

        if ($fullName !== '') {
            $split = preg_split('/\s+/', trim($fullName), 2);
            $firstName = (string) ($split[0] ?? '');
            $lastName = (string) ($split[1] ?? '');
        }

        return [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'fullName' => $fullName,
            'verified' => $fullName !== '',
        ];
    }

    /**
     * @param array<string, mixed> $card
     * @param array{firstName: string, lastName: string, fullName: string, verified: bool} $holderData
     * @return array<string, mixed>
     */
    private function applyCardHolderData(array $card, array $holderData): array
    {
        $card['cardHolderFirstName'] = $holderData['firstName'];
        $card['cardHolderLastName'] = $holderData['lastName'];
        $card['cardHolderName'] = $holderData['fullName'];
        $card['holderName'] = $holderData['fullName'];

        return $card;
    }

    /**
     * Resolve the display name from Shopware billing/customer data.
     *
     * @return array{firstName: string, lastName: string, fullName: string}
     */
    public function resolveShopwareCardHolderData(?CustomerEntity $customer = null, ?CustomerAddressEntity $billingAddress = null): array
    {
        $firstName = '';
        $lastName = '';
        $company = '';

        if ($billingAddress) {
            $firstName = trim((string) ($billingAddress->getFirstName() ?? ''));
            $lastName = trim((string) ($billingAddress->getLastName() ?? ''));
            $company = trim((string) ($billingAddress->getCompany() ?? ''));
        }

        if ($firstName !== '' || $lastName !== '') {
            $fullName = trim($firstName . ' ' . $lastName);
        } elseif ($company !== '') {
            $fullName = $company;
        } else {
            $fullName = '';
        }

        return [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'fullName' => $fullName,
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<int, string> $keys
     */
    private function extractFirstStringValue(array $fields, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $fields[$key] ?? null;

            if (\is_string($value)) {
                $value = trim($value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }
    
    
    
}
