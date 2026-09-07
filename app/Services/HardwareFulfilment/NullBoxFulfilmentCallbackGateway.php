<?php

namespace App\Services\HardwareFulfilment;

use App\Contracts\HardwareFulfilment\BoxFulfilmentCallbackGateway;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentCallbackRequest;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentCallbackResult;

/**
 * Production-safe default. Makes no HTTP call to radiumbox.com.
 */
final class NullBoxFulfilmentCallbackGateway implements BoxFulfilmentCallbackGateway
{
    public const MESSAGE = 'Box fulfilment callback is disabled. No HTTP was sent.';

    public function provider(): string
    {
        return 'none';
    }

    public function send(HardwareFulfilmentCallbackRequest $request): HardwareFulfilmentCallbackResult
    {
        return new HardwareFulfilmentCallbackResult(
            status: 'disabled',
            accepted: false,
            retryable: false,
            error: self::MESSAGE,
        );
    }
}
