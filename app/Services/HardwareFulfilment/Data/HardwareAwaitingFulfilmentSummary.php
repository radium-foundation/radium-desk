<?php

namespace App\Services\HardwareFulfilment\Data;

final class HardwareAwaitingFulfilmentSummary
{
    public function __construct(
        public readonly int $rdeTotal,
        public readonly int $withFulfilment,
        public readonly int $withoutFulfilment,
        public readonly int $reviewCandidates,
        public readonly int $frozen,
        public readonly int $hold,
        public readonly int $blocked,
        public readonly int $unpaid,
        public readonly int $preCutoff,
        public readonly int $deskAlreadyCompleted,
        public readonly int $rin,
    ) {}
}
