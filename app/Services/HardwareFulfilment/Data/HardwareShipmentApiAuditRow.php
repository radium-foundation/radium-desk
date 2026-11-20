<?php

namespace App\Services\HardwareFulfilment\Data;

final class HardwareShipmentApiAuditRow
{
    public function __construct(
        public readonly string $activity,
        public readonly string $recordedAtIst,
        public readonly string $operation,
        public readonly string $outcome,
        public readonly int $httpStatus,
        public readonly ?string $providerMessage,
        public readonly string $detailLine,
    ) {}
}
