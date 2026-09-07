<?php

namespace App\Contracts\HardwareFulfilment;

use App\Services\HardwareFulfilment\Data\HardwareFulfilmentCallbackRequest;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentCallbackResult;

/**
 * Desk → radiumbox.com fulfilment callback. Domain code must not call HTTP.
 * Production binds the Null adapter until explicitly enabled.
 */
interface BoxFulfilmentCallbackGateway
{
    public function provider(): string;

    public function send(HardwareFulfilmentCallbackRequest $request): HardwareFulfilmentCallbackResult;
}
