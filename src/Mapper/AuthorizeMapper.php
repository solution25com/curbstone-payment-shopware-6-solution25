<?php

declare(strict_types=1);

namespace Curbstone\Mapper;

use Curbstone\Dto\AuthorizeRequest;
use Curbstone\Dto\AuthorizeResponse;

/**
 *
 * @phpstan-type CurbstoneCreds array{
 *     dsiKey: non-empty-string,
 *     customerId: non-empty-string,
 *     merchantCode: non-empty-string
 * }
 *
 * @phpstan-type CurbstonePayload array<string, non-empty-string>
 *
 * @phpstan-type CurbstoneRawResponse array<string, mixed>
 */
interface AuthorizeMapper
{
    /**
     * @param CurbstoneCreds   $creds
     * @return CurbstonePayload
     */
    public function toPayload(AuthorizeRequest $req, array $creds): array;

    /**
     * @param CurbstoneRawResponse $raw
     */
    public function fromResponse(array $raw): AuthorizeResponse;
}
