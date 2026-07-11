<?php

namespace App\Support\Customer360\Journey\Contributors;

use App\Contracts\AI\CustomerJourneyMilestoneContributor;
use App\Data\AI\CustomerJourneyBuildContext;
use App\Data\AI\CustomerJourneyMilestoneDTO;
use App\Data\ServiceCaseTimelineEntry;
use App\Enums\AI\CustomerJourneyMilestoneType;
use App\Enums\SupportAppointmentStatus;
use App\Services\ServiceCaseActivityTimelineService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EngagementMilestoneContributor implements CustomerJourneyMilestoneContributor
{
    public function __construct(
        private readonly ServiceCaseActivityTimelineService $activityTimelineService,
    ) {}

    public function contribute(CustomerJourneyBuildContext $context): array
    {
        $milestones = [];
        $activities = $this->activities($context);
        $waitingState = $context->waitingState;

        if (is_array($waitingState) && ! $this->shouldSuppressWaiting($context)) {
            $waitingSince = $waitingState['customer_waiting_since']
                ?? ($waitingState['lifecycle_history']['customer_waiting_since'] ?? null);

            if ($waitingSince instanceof Carbon) {
                $reason = $waitingState['reason_label']
                    ?? ($waitingState['lifecycle_history']['waiting_reason_label'] ?? 'customer input');

                $milestones[] = new CustomerJourneyMilestoneDTO(
                    type: CustomerJourneyMilestoneType::WaitingForCustomer,
                    title: 'Waiting for '.Str::lower((string) $reason),
                    timestamp: $waitingSince,
                    status: 'active',
                    actor: null,
                    source: 'waiting_state',
                    confidence: 90,
                );
            }
        }

        foreach ($activities as $entry) {
            if (! $entry instanceof ServiceCaseTimelineEntry) {
                continue;
            }

            $title = Str::lower($entry->title);

            if (Str::contains($title, 'waiting for customer input') && ! $this->shouldSuppressWaiting($context)) {
                $milestones[] = new CustomerJourneyMilestoneDTO(
                    type: CustomerJourneyMilestoneType::WaitingForCustomer,
                    title: $this->waitingTitleFromBody($entry),
                    timestamp: $entry->occurredAt,
                    status: $context->incident->activeWaitingState !== null ? 'active' : 'completed',
                    actor: $entry->actor->displayName,
                    source: 'audit',
                    confidence: 95,
                );
            }

            if ($this->isCustomerReply($title)) {
                $milestones[] = new CustomerJourneyMilestoneDTO(
                    type: CustomerJourneyMilestoneType::CustomerReplied,
                    title: CustomerJourneyMilestoneType::CustomerReplied->label(),
                    timestamp: $entry->occurredAt,
                    status: 'completed',
                    actor: $entry->actor->displayName,
                    source: 'audit',
                    confidence: 85,
                );
            }
        }

        return $this->dedupeWaitingMilestones($milestones);
    }

    private function activities(CustomerJourneyBuildContext $context): Collection
    {
        if ($context->activityTimeline instanceof Collection) {
            return $context->activityTimeline;
        }

        return $this->activityTimelineService->forIncident($context->incident);
    }

    private function waitingTitleFromBody(ServiceCaseTimelineEntry $entry): string
    {
        $body = Str::lower((string) $entry->body);

        if (Str::contains($body, 'reason:')) {
            $reason = trim(Str::after($body, 'reason:'));

            return 'Waiting for '.$reason;
        }

        return CustomerJourneyMilestoneType::WaitingForCustomer->label();
    }

    private function isCustomerReply(string $title): bool
    {
        return Str::contains($title, [
            'corrected by ira',
            'serial validation successful',
            'radiumbox verification successful',
        ]);
    }

    /**
     * @param  list<CustomerJourneyMilestoneDTO>  $milestones
     * @return list<CustomerJourneyMilestoneDTO>
     */
    private function dedupeWaitingMilestones(array $milestones): array
    {
        $waiting = [];
        $others = [];

        foreach ($milestones as $milestone) {
            if ($milestone->type === CustomerJourneyMilestoneType::WaitingForCustomer) {
                $waiting[] = $milestone;

                continue;
            }

            $others[] = $milestone;
        }

        if ($waiting === []) {
            return $others;
        }

        $bestWaiting = collect($waiting)
            ->sortByDesc(fn (CustomerJourneyMilestoneDTO $milestone) => $milestone->confidence)
            ->first();

        return array_merge($others, $bestWaiting !== null ? [$bestWaiting] : []);
    }

    private function shouldSuppressWaiting(CustomerJourneyBuildContext $context): bool
    {
        if (($context->supportAppointment['is_completed'] ?? false)) {
            return true;
        }

        if ($context->incident->relationLoaded('supportAppointments')) {
            foreach ($context->incident->supportAppointments as $appointment) {
                if ($appointment->status === SupportAppointmentStatus::Completed) {
                    return true;
                }
            }
        }

        return false;
    }
}
