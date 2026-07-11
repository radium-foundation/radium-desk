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
     * @param  array{
     *     status: \App\Enums\SupportAppointmentStatus,
     *     preferred_date: \Illuminate\Support\Carbon,
     *     preferred_time_slot: \App\Enums\SupportAppointmentTimeSlot|null,
     *     time_slot_label: ?string,
     *     created_at: \Illuminate\Support\Carbon|null,
     *     updated_at: \Illuminate\Support\Carbon|null,
     *     completed_at: ?\Illuminate\Support\Carbon,
     *     assignee_name: ?string,
     *     is_active: bool,
     *     is_completed: bool,
     * }|null  $supportAppointment
     * @param  CustomerJourneyDTO|null  $customerJourney
     */
    public function __construct(
        public ?array $customerSummary = null,
        public ?array $activeServices = null,
        public ?array $enrichmentMetadata = null,
        public ?TimelineViewModel $timeline = null,
        public ?array $waitingStateCard = null,
        public ?array $supportAppointment = null,
        public ?CustomerJourneyDTO $customerJourney = null,
    ) {}
}
