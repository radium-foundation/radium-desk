<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Models\Shipment;
use App\Services\Shipping\Data\ShiprocketAwbResult;
use App\Services\Shipping\Data\ShiprocketTrackResult;

final class HardwareProviderCourierReassignOutcome
{
    /**
     * @param  array<string, mixed>  $walletSnapshot
     */
    public function __construct(
        public readonly Shipment $shipment,
        public readonly string $previousAwb,
        public readonly ?string $newAwb,
        public readonly ShiprocketAwbResult $providerResult,
        public readonly ShiprocketTrackResult $preflightTrack,
        public readonly ?ShiprocketTrackResult $postAssignTrack,
        public readonly bool $postedToProvider,
        public readonly bool $deskUpdated,
        public readonly bool $alreadyPosted,
        public readonly array $walletSnapshot,
    ) {}

    public function ambiguous(): bool
    {
        return $this->providerResult->status === 'ambiguous'
            || ($this->providerResult->status === 'failed' && $this->providerResult->retryable);
    }
}
