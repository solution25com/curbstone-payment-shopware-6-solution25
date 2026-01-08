<?php

declare(strict_types=1);

namespace Curbstone\Contract;

interface CurbstoneHttp
{
    /**
     * @param array<string,string> $payload
     * @return array<string,string>
     */
    public function post(
        string $baseUrl,
        array $payload,
        int $retries,
        int $backoffMs
    ): array;
}
