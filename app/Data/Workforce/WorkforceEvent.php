<?php

namespace App\Data\Workforce;

use App\Enums\WorkforceEventType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Additive WorkforceEvent projection fact (architecture concept).
 * Not event sourcing. Not a replacement for workforce_attendance_days.
 */
readonly class WorkforceEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public WorkforceEventType $type,
        public int $userId,
        public Carbon $occurredAt,
        public ?Carbon $workDate = null,
        public array $payload = [],
        public ?string $id = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function make(
        WorkforceEventType $type,
        int $userId,
        ?Carbon $workDate = null,
        array $payload = [],
        ?Carbon $occurredAt = null,
    ): self {
        return new self(
            type: $type,
            userId: $userId,
            occurredAt: ($occurredAt ?? now())->copy(),
            workDate: $workDate?->copy()->startOfDay(),
            payload: $payload,
            id: (string) Str::uuid(),
        );
    }
}
