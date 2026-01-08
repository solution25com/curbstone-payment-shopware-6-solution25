<?php

declare(strict_types=1);

namespace Curbstone\Config;

use Shopware\Core\System\SystemConfig\SystemConfigService;

final class CurbstoneConfigProvider
{
    private const DOMAIN = 'Curbstone.config';

    public function __construct(private readonly SystemConfigService $cfg)
    {
    }

    public function forSalesChannel(?string $salesChannelId): CurbstoneConfig
    {
        $d = self::DOMAIN;

        $envEnabled = $this->envToBool($_ENV['CURBSTONE_GATEWAY_ENABLED'] ?? null, false);
        $enabled            = $this->getBool("$d.enabled", $salesChannelId, $envEnabled);
        $sandbox            = $this->getBool("$d.sandbox", $salesChannelId, true);
        $dsiKey             = $this->getString("$d.dsiKey", $salesChannelId, '');
        $customerId         = $this->getString("$d.customerId", $salesChannelId, '');
        $merchantCode       = $this->getString("$d.merchantCode", $salesChannelId, '');
        $flow               = $this->getString("$d.authCaptureFlow", $salesChannelId, 'auth_only');
        $checkoutIntegration = $this->getString("$d.checkoutIntegration", $salesChannelId, 'plp');
        $plpMode            = $this->getString("$d.plpMode", $salesChannelId, 'embedded');
        $retries            = $this->getInt("$d.retries", $salesChannelId, 2);
        $backoffMs          = $this->getInt("$d.backoffMs", $salesChannelId, 120);
        $flow     = \in_array($flow, ['auth_only', 'auth_capture'], true) ? $flow : 'auth_only';
        $plpMode  = \in_array($plpMode, ['embedded', 'redirect'], true) ? $plpMode : 'embedded';

        if ($enabled) {
            if ($dsiKey === '' || $customerId === '' || $merchantCode === '') {
                throw new \RuntimeException('Curbstone not configured (dsiKey/customerId/merchantCode).');
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
            backoffMs:           \max(20, $backoffMs)
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

    private function envToBool(null|string $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        $parsed = \filter_var($value, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);
        return $parsed ?? $default;
    }
}
