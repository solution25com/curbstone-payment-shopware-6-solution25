<?php

declare(strict_types=1);

namespace Curbstone\Contract;

use Curbstone\Dto\AuthorizeRequest;
use Curbstone\Dto\AuthorizeResponse;

interface CurbstoneGateway
{
    public function authorize(AuthorizeRequest $req): AuthorizeResponse;
}
