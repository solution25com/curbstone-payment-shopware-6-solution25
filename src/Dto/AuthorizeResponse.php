<?php

declare(strict_types=1);

namespace Curbstone\Dto;

/**
 * @phpstan-type RawResponse array<string, mixed>
 */
final class AuthorizeResponse
{
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_DECLINED = 'DECLINED';
    public const STATUS_ERROR    = 'ERROR';

    /**
     * @param RawResponse $raw
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $token,
        public readonly ?string $networkRef,
        public readonly ?string $message,
        public readonly array $raw
    ) {
    }
}
