<?php

declare(strict_types=1);

namespace Curbstone\Gateways;

use Curbstone\Contract\CurbstoneGateway;
use Curbstone\Contract\CurbstoneHttp;
use Curbstone\Config\CurbstoneConfigProvider;
use Curbstone\Dto\AuthorizeRequest;
use Curbstone\Dto\AuthorizeResponse;
use Curbstone\Mapper\AuthorizeMapper;

final class CurbstoneGatewayImpl implements CurbstoneGateway
{
    public function __construct(
        private CurbstoneHttp $http,
        private CurbstoneConfigProvider $config,
        private AuthorizeMapper $mapper,
    ) {
    }

    public function authorize(AuthorizeRequest $req): AuthorizeResponse
    {
        $cfg = $this->config->forSalesChannel($req->salesChannelId);

        $dsiKey       = $cfg->dsiKey;
        $customerId   = $cfg->customerId;
        $merchantCode = $cfg->merchantCode;

        if ($dsiKey === '' || $customerId === '' || $merchantCode === '') {
            throw new \InvalidArgumentException('Curbstone credentials must be non-empty (dsiKey, customerId, merchantCode).');
        }

        /** @var non-empty-string $dsiKey */
        /** @var non-empty-string $customerId */
        /** @var non-empty-string $merchantCode */

        $payload = $this->mapper->toPayload($req, [
            'dsiKey'       => $dsiKey,
            'customerId'   => $customerId,
            'merchantCode' => $merchantCode,
        ]);

        $raw = $this->http->post(
            baseUrl:   $this->config->dsiBaseUrl($cfg->sandbox),
            payload:   $payload,
            retries:   $cfg->retries,
            backoffMs: $cfg->backoffMs
        );

        return $this->mapper->fromResponse($raw);
    }
}
