<?php

namespace App\Support\Customer360\Journey\Contributors;

use App\Contracts\AI\CustomerJourneyMilestoneContributor;
use App\Data\AI\CustomerJourneyBuildContext;
use App\Data\AI\CustomerJourneyMilestoneDTO;
use App\Data\ServiceCaseTimelineEntry;
use App\Enums\AI\CustomerJourneyMilestoneType;
use App\Enums\IncidentStatus;
use App\Services\ServiceCaseActivityTimelineService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LifecycleMilestoneContributor implements CustomerJourneyMilestoneContributor
{
    public function __construct(
        private readonly ServiceCaseActivityTimelineService $activityTimelineService,
    ) {}

    public function contribute(CustomerJourneyBuildContext $context): array
    {
        $milestones = [];
        $activities = $this->activities($context);

        foreach ($activities as $entry) {
            if (! $entry instanceof ServiceCaseTimelineEntry) {
                continue;
            }

            $title = Str::lower($entry->title);

            if (Str::contains($title, 'case reopened')) {
                $milestones[] = new CustomerJourneyMilestoneDTO(
                    type: CustomerJourneyMilestoneType::Reopened,
                    title: CustomerJourneyMilestoneType::Reopened->label(),
                    timestamp: $entry->occurredAt,
                    status: 'completed',
                    actor: $entry->actor->displayName,
                    source: 'audit',
                    confidence: 95,
                );
            }

            if (Str::contains($title, 'service case closed')
                || Str::contains($title, 'closed automatically')) {
                $milestones[] = new CustomerJourneyMilestoneDTO(
                    type: CustomerJourneyMilestoneType::Closed,
                    title: CustomerJourneyMilestoneType::Closed->label(),
                    timestamp: $entry->occurredAt,
                    status: 'completed',
                    actor: $entry->actor->displayName,
                    source: 'audit',
                    confidence: 95,
                );
            }
        }

        if ($context->incident->status === IncidentStatus::Closed
            && ! $this->hasClosedMilestone($milestones)) {
            $closedAt = $context->incident->updated_at ?? $context->incident->created_at ?? now();

            $milestones[] = new CustomerJourneyMilestoneDTO(
                type: CustomerJourneyMilestoneType::Closed,
                title: CustomerJourneyMilestoneType::Closed->label(),
                timestamp: $closedAt,
                status: 'completed',
                actor: null,
                source: 'incident',
                confidence: 70,
            );
        }

        return $milestones;
    }

    private function activities(CustomerJourneyBuildContext $context): Collection
    {
        if ($context->activityTimeline instanceof Collection) {
            return $context->activityTimeline;
        }

        return $this->activityTimelineService->forIncident($context->incident);
    }

    /**
     * @param  list<CustomerJourneyMilestoneDTO>  $milestones
     */
    private function hasClosedMilestone(array $milestones): bool
    {
        foreach ($milestones as $milestone) {
            if ($milestone->type === CustomerJourneyMilestoneType::Closed) {
                return true;
            }
        }

        return false;
    }
}
