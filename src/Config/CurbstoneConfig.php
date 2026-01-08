<?php

declare(strict_types=1);

namespace Curbstone\Config;

final readonly class CurbstoneConfig
{
    public function __construct(
        public bool $enabled,
        public bool $sandbox,
        public string $dsiKey,
        public string $customerId,
        public string $merchantCode,
        public string $authCaptureFlow,
        public string $plpMode,
        public string $checkoutIntegration,
        public int $retries,
        public int $backoffMs
    ) {
    }

    public function isAuthOnly(): bool
    {
        return $this->authCaptureFlow === 'auth_only';
    }

    public function isEmbeddedPlp(): bool
    {
        return $this->plpMode === 'embedded';
    }
}
