<?php declare(strict_types=1);

namespace Curbstone\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;

final class CurbstoneRequestFactory
{

    public function buildPreauthBody(
        object $cfg,
        string $ctxToken,
        array $address,
        string $mpcusf,
        string $returnUrl,
        string $mfname = ''
    ): array {
        return [
            'MFCUST' => $cfg->customerId,
            'MFMRCH' => $cfg->merchantCode,
            'MFTYPE' => 'RA',
            'MFTYP2' => 'PA',
            'MFMETH' => '02',
            'MFORDR' => 'ctx_' . $ctxToken,
            'MFREFR' => 'ctx_' . $ctxToken,
            'MFAMT1' => '0.00',
            'MFADD1' => $this->truncate($address['addr1'] ?? '', 40),
            'MFCITY' => $this->truncate($address['city'] ?? '', 30),
            'MFSTAT' => $this->truncate($address['state'] ?? '', 10),
            'MFZIPC' => $this->truncate($address['zip'] ?? '', 10),
            'MFDSTZ' => $this->truncate($address['dstZip'] ?? ($address['zip'] ?? ''), 10),
            'MPCUST' => $cfg->customerId,
            'MPCUSF' => $mpcusf,
            'MFNAME' => $this->truncate(trim($mfname), 40),
            'MPTRGT' => $returnUrl,
            'MPTRGC' => 'UTF-8',
        ];
    }

    public function buildRealChargeBody(
        object $cfg,
        OrderEntity $order,
        OrderTransactionEntity $orderTx,
        string $amount,
        array $billing,
        array $cardData,
        string $finishUrl,
        ?string $forcedSubtype = null
    ): array {
        $subtype = $forcedSubtype !== null && $forcedSubtype !== ''
            ? strtoupper(trim($forcedSubtype))
            : (($cfg->authCaptureFlow === 'auth_capture') ? 'SA' : 'PA');

        $body = [
            'MFCUST' => $cfg->customerId,
            'MFMRCH' => $cfg->merchantCode,
            'MFTYPE' => 'RA',
            'MFTYP2' => $subtype,
            'MFMETH' => '02',
            'MFORDR' => $order->getOrderNumber() ?? $orderTx->getId(),
            'MFREFR' => $orderTx->getId(),
            'MFAMT1' => $amount,
            'MFADD1' => $this->truncate($billing['addr1'] ?? '', 40),
            'MFCITY' => $this->truncate($billing['city'] ?? '', 30),
            'MFSTAT' => $this->truncate($billing['state'] ?? '', 10),
            'MFZIPC' => $this->truncate($billing['zip'] ?? '', 10),
            'MFDSTZ' => $this->truncate($billing['zip'] ?? '', 10),
            'MFKEYP' => (string) $cardData['mfkeyp'],
            'MPTRGT' => $finishUrl,
            'MPCUST' => $cfg->customerId,
            'MPCUSF' => (string) ($order->getOrderCustomer()?->getCustomerNumber() ?? ''),
        ];

        if (!empty($cardData['usingSavedCard'])) {
            $body['MFRTRN'] = 'VAULTED';
        } else {
            $body['MFRTRN'] = (string) ($cardData['token'] ?? '');
        }

        return $body;
    }

    public function buildRefundBody(
        object $cfg,
        OrderEntity $order,
        OrderTransactionEntity $orderTx,
        array $txMeta,
        string $amount
    ): array {
        $billing         = $this->billingFromOrder($order);
        $realChargeReq   = $txMeta['realCharge']['request'] ?? [];
        $mptrgt          = (string) ($realChargeReq['MPTRGT'] ?? '');
        $mptrgc          = (string) ($realChargeReq['MPTRGC'] ?? 'UTF-8');
        $mfrtrn          = (string) ($realChargeReq['MFRTRN'] ?? '');

        $body = [
            'MFCUST' => $cfg->customerId,
            'MFMRCH' => $cfg->merchantCode,
            'MFTYPE' => 'RA',
            'MFTYP2' => ($cfg->authCaptureFlow === 'auth_capture') ? 'SA' : 'PA',
            'MFMETH' => '02',
            'MFORDR' => $order->getOrderNumber() ?? $orderTx->getId(),
            'MFREFR' => $orderTx->getId(),
            'MFAMT1' => $amount,
            'MFADD1' => $this->truncate($billing['addr1'] ?? '', 40),
            'MFCITY' => $this->truncate($billing['city'] ?? '', 30),
            'MFSTAT' => $this->truncate($billing['state'] ?? '', 10),
            'MFZIPC' => $this->truncate($billing['zip'] ?? '', 10),
            'MFDSTZ' => $this->truncate($billing['zip'] ?? '', 10),
            'MFSESS' => (string) ($txMeta['mfSess'] ?? ''),
            'MFKEYP' => (string) ($txMeta['mfkeyp'] ?? ''),
            'MPCUST' => $cfg->customerId,
            'MPCUSF' => (string) ($order->getOrderCustomer()?->getCustomerNumber() ?? ''),
            'MPTRGT' => $mptrgt,
            'MPTRGC' => $mptrgc,
            'MFRTRN' => $mfrtrn,
        ];

        return $body;
    }

    public function buildVoidBody(
        object $cfg,
        OrderEntity $order,
        OrderTransactionEntity $orderTx,
        array $txMeta
    ): array {
        $amount = (string) ($txMeta['amount'] ?? '0.00');
        $realChargeReq = $txMeta['realCharge']['request'] ?? [];
        $mptrgt        = (string) ($realChargeReq['MPTRGT'] ?? '');
        $mptrgc        = (string) ($realChargeReq['MPTRGC'] ?? 'UTF-8');
        $body = [
            'MFCUST' => $cfg->customerId,
            'MFMRCH' => $cfg->merchantCode,
            'MFTYPE' => 'CR',     
            'MFTYP2' => 'VO',     
            'MFMETH' => '02',
            'MFORDR' => $order->getOrderNumber() ?? $orderTx->getId(),
            'MFREFR' => $orderTx->getId(),
            'MFAMT1' => $amount,                          
            'MFSESS' => (string) ($txMeta['mfSess'] ?? ''),
            'MFKEYP' => (string) ($txMeta['mfkeyp'] ?? ''),
            'MPTRGT' => $mptrgt,
            'MPTRGC' => $mptrgc,
        ];

        return $body;
    }

    /**
     * @return array{addr1: string, city: string, state: string, zip: string}
     */
    private function billingFromOrder(OrderEntity $order): array
    {
        $billing = $order->getBillingAddress();

        $addr1 = \trim(
            ($billing?->getStreet() ?? '') . ' ' .
            ($billing?->getAdditionalAddressLine1() ?? '')
        );
        $city  = (string) ($billing?->getCity() ?? '');
        $state = (string) (
            $billing?->getCountryState()?->getShortCode()
            ?? $billing?->getCountryState()?->getName()
            ?? ''
        );
        $zip   = (string) ($billing?->getZipcode() ?? '');

        return [
            'addr1' => $addr1,
            'city'  => $city,
            'state' => $state,
            'zip'   => $zip,
        ];
    }

    private function truncate(string $value, int $len): string
    {
        return mb_substr($value, 0, $len);
    }
}
