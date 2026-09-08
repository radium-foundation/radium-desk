<?php

namespace Tests\Feature\HardwareFulfilment\Support;

use App\Models\HardwareFulfilment;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareShipmentCourierOptionsService;

trait SelectsHardwareTestCourier
{
    protected function selectTestCourier(HardwareFulfilment $fulfilment, ?User $actor = null): HardwareFulfilment
    {
        $service = app(HardwareShipmentCourierOptionsService::class);
        $service->fetch($fulfilment, $actor);
        $fresh = $fulfilment->fresh() ?? $fulfilment;
        $options = is_array($fresh->courier_options_snapshot['options'] ?? null)
            ? $fresh->courier_options_snapshot['options']
            : [];
        $id = (string) ($options[0]['courier_id'] ?? '12');
        $service->select($fresh, $id, $actor);

        return $fresh->fresh() ?? $fresh;
    }
}
