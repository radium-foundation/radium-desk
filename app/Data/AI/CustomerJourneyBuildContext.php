<?php

namespace App\Data\AI;

use App\Data\SerialInsight;
use App\Data\TimelineViewModel;
use App\Models\Incident;
use Illuminate\Support\Collection;

readonly class CustomerJourneyBuildContext
{
    /**
     * @param  array{label: string, occurred_at: \Illuminate\Support\Carbon}|null  $lastPayment
     * @param  array<string, mixed>|null  $waitingState
     * @param  array<string, mixed>|null  $supportAppointment
     */
    public function __construct(
        public Incident $incident,
        public ?array $lastPayment = null,
        public ?array $waitingState = null,
        public ?array $supportAppointment = null,
        public bool $serialMissing = false,
        public ?string $deviceModel = null,
        public ?TimelineViewModel $timeline = null,
        public ?SerialInsight $serialInsight = null,
        public ?Collection $activityTimeline = null,
    ) {}
}
