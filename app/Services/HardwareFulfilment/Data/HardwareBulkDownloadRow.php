<?php

namespace App\Services\HardwareFulfilment\Data;

final class HardwareBulkDownloadRow
{
    public function __construct(
        public readonly int $fulfilmentId,
        public readonly string $sourceId,
        public readonly ?string $awb,
        public readonly ?string $carrier,
        public readonly ?string $shipmentStatus,
        public readonly bool $labelAvailable,
        public readonly bool $manifestAvailable,
        public readonly ?string $manifestIdentity,
        public readonly string $result,
        public readonly ?string $exclusionReason = null,
        public readonly ?string $downloadFailure = null,
    ) {}
}
