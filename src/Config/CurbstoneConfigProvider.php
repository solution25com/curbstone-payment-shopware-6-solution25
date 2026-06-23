<?php declare(strict_types=1);

namespace Curbstone\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;

final class CurbstoneConfigProvider
{
    private const DOMAIN = 'Curbstone.config';

    public function __construct(private readonly SystemConfigService $cfg) {}

    public function forSalesChannel(?string $salesChannelId): CurbstoneConfig
    {
        $d = self::DOMAIN;
        $envEnabled = $this->envToBool($_ENV['CURBSTONE_GATEWAY_ENABLED'] ?? null, false);
        $enabled = true;
        $sandbox            = $this->getBool("$d.sandbox",              $salesChannelId, true);
        $dsiKey             = $this->getString("$d.dsiKey",             $salesChannelId, '');
        $customerId         = $this->getString("$d.customerId",         $salesChannelId, '');
        $merchantCode       = $this->getString("$d.merchantCode",       $salesChannelId, '');
        if ($merchantCode === '') {
            $merchantCode = $this->getString("$d.merchantId",           $salesChannelId, '');
        }
        $flow               = $this->getString("$d.authCaptureFlow",    $salesChannelId, 'auth_only');
        $checkoutIntegration= $this->getString("$d.checkoutIntegration",$salesChannelId, 'plp');
        $plpMode            = $this->getString("$d.plpMode",            $salesChannelId, 'embedded');
        $retries            = $this->getInt("$d.retries",               $salesChannelId, 2);
        $backoffMs          = $this->getInt("$d.backoffMs",             $salesChannelId, 120);
        $disableSubscribers = $this->getBool("$d.disableSubscribers",   $salesChannelId, false);
        $verifyTls = $this->getBool("$d.verifyTls", $salesChannelId, true);
        $highValueThreshold = $this->getFloat("$d.highValueThreshold", $salesChannelId, 5000.0);
        $envVerify = $_ENV['CURBSTONE_HTTP_VERIFY'] ?? \getenv('CURBSTONE_HTTP_VERIFY');
        $envThreshold = $_ENV['CURBSTONE_HIGH_VALUE_THRESHOLD'] ?? \getenv('CURBSTONE_HIGH_VALUE_THRESHOLD');
        if ($envVerify !== false && $envVerify !== null && $envVerify !== '') {
            $verifyTls = $this->envToBool((string) $envVerify, $verifyTls);
        }
        if ($envThreshold !== false && $envThreshold !== null && $envThreshold !== '' && \is_numeric((string) $envThreshold)) {
            $highValueThreshold = (float) $envThreshold;
        }
        $flow     = \in_array($flow, ['auth_only', 'auth_capture'], true) ? $flow : 'auth_only';
        $plpMode  = \in_array($plpMode, ['embedded', 'redirect'], true) ? $plpMode : 'embedded';
        $checkoutIntegration = \in_array($checkoutIntegration, ['plp', 'dsi'], true) ? $checkoutIntegration : 'plp';

        if ($enabled && $checkoutIntegration === 'dsi') {
            if ($dsiKey === '' || $customerId === '' || $merchantCode === '') {
                throw new \RuntimeException('Curbstone not configured for DSI (dsiKey/customerId/merchantId).');
            }
        }

        return new CurbstoneConfig(
            enabled:             $enabled,
            sandbox:             $sandbox,
            dsiKey:              $dsiKey,
            customerId:          $customerId,
            merchantCode:        $merchantCode,
            authCaptureFlow:     $flow,
            plpMode:             $plpMode,
            checkoutIntegration: $checkoutIntegration,
            retries:             \max(0, $retries),
            backoffMs:           \max(20, $backoffMs),
            disableSubscribers:  $disableSubscribers,
            verifyTls:           $verifyTls,
            highValueThreshold:  \max(0.0, $highValueThreshold),
        );
    }

    public function dsiBaseUrl(bool $sandbox): string
    {
        return $sandbox ? 'https://c3sbx.net/dsi/' : 'https://c3dsi.net/dsi/';
    }

    public function plpBaseUrl(bool $sandbox): string
    {
        return $sandbox ? 'https://c3sbx.net/curbstone/plp/' : 'https://c3plp.net/curbstone/plp/';
    }

    public function isSubscribersDisabled(?string $salesChannelId): bool
    {
        return $this->forSalesChannel($salesChannelId)->disableSubscribers;
    }

    private function getString(string $key, ?string $salesChannelId, string $default): string
    {
        $v = $this->cfg->get($key, $salesChannelId);
        return \is_string($v) ? $v : $default;
    }

    private function getInt(string $key, ?string $salesChannelId, int $default): int
    {
        $v = $this->cfg->get($key, $salesChannelId);

        if (\is_int($v)) {
            return $v;
        }
        if (\is_string($v) && \is_numeric($v)) {
            return (int) $v;
        }
        if (\is_float($v)) {
            return (int) \round($v);
        }

        return $default;
    }

    private function getFloat(string $key, ?string $salesChannelId, float $default): float
    {
        $v = $this->cfg->get($key, $salesChannelId);

        if (\is_float($v) || \is_int($v)) {
            return (float) $v;
        }
        if (\is_string($v) && \is_numeric($v)) {
            return (float) $v;
        }

        return $default;
    }

    private function getBool(string $key, ?string $salesChannelId, bool $default): bool
    {
        $v = $this->cfg->get($key, $salesChannelId);

        if (\is_bool($v)) {
            return $v;
        }
        if (\is_string($v)) {
            $parsed = \filter_var($v, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);
            return $parsed ?? $default;
        }
        if (\is_int($v)) {
            return $v !== 0;
        }
        if (\is_float($v)) {
            return $v != 0.0;
        }

        return $default;
    }

    private function envToBool(?string $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        $parsed = \filter_var($value, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }
}
