<?php

namespace App\Services\HardwareFulfilment\Data;

final class HardwareInclusiveGstAmounts
{
    public function __construct(
        public readonly float $gstPercentage,
        public readonly float $taxableValue,
        public readonly float $taxTotal,
        public readonly float $lineTotal,
    ) {}
}
