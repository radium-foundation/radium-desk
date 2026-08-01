<?php

namespace App\Data\Workforce;

use Illuminate\Support\Carbon;

readonly class PayrollMonthLockStatus
{
    public function __construct(
        public string $state,
        public Carbon $month,
        public ?string $lockedBy = null,
        public ?int $lockedById = null,
        public ?Carbon $lockedOn = null,
        public ?string $reason = null,
    ) {}

    public function isLocked(): bool
    {
        return $this->state === 'locked';
    }

    public function isOpen(): bool
    {
        return $this->state === 'open';
    }
}
