<?php

namespace App\Data\AI;

use App\Data\TimelineViewModel;

readonly class AIContextBuildSnapshot
{
    /**
     * @param  array<string, int>|null  $customerSummary
     * @param  list<array{label: string, status: string, variant: string}>|null  $activeServices
     * @param  array<string, mixed>|null  $enrichmentMetadata
     * @param  array<string, mixed>|null  $waitingStateCard
     * @param  array{preferred_date: \Illuminate\Support\Carbon, time_slot_label: ?string, assignee_name: ?string}|null  $scheduledSupportAppointment
     */
    public function __construct(
        public ?array $customerSummary = null,
        public ?array $activeServices = null,
        public ?array $enrichmentMetadata = null,
        public ?TimelineViewModel $timeline = null,
        public ?array $waitingStateCard = null,
        public ?array $scheduledSupportAppointment = null,
    ) {}
}
