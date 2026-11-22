<?php

namespace App\Services\PreShipmentCancellation;

use Illuminate\Validation\ValidationException;

final class PreShipmentCancellationFeature
{
    public function enabled(): bool
    {
        return (bool) config('pre_shipment_cancellation.enabled', false);
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages([
                'pre_shipment_cancellation' => 'Pre-shipment cancellation is not enabled on this environment.',
            ]);
        }
    }
}
