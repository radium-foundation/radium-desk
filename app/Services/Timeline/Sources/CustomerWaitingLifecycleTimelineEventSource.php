<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineEvent;
use App\Enums\TimelineEventType;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Services\Automation\CustomerWaitingLifecycleService;
use App\Services\AutomationIdentityService;
use App\Support\AppDateFormatter;
use App\Support\Timeline\TimelineActorPresenter;
use App\Support\Timeline\TimelineWaitingReasonPresenter;
use Illuminate\Support\Collection;

class CustomerWaitingLifecycleTimelineEventSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
        private readonly AutomationIdentityService $automationIdentity,
    ) {}

    public function collect(?int $limit = null): Collection
    {
        $this->order->loadMissing('incidents.waitingStates');

        $waitingStates = $this->order->incidents
            ->flatMap(fn (Incident $incident) => $incident->waitingStates)
            ->sortByDesc(fn (IncidentWaitingState $state) => $state->started_at?->timestamp ?? 0)
            ->values();

        if ($limit !== null) {
            $waitingStates = $waitingStates->take($limit);
        }

        return $waitingStates
            ->map(fn (IncidentWaitingState $state): ?TimelineEvent => $this->mapWaitingState($state))
            ->filter()
            ->values();
    }

    private function mapWaitingState(IncidentWaitingState $waitingState): ?TimelineEvent
    {
        if ($waitingState->started_at === null) {
            return null;
        }

        $resolution = $this->resolveResolution($waitingState);
        $awaitingLabel = TimelineWaitingReasonPresenter::awaitingLabel($waitingState->waiting_reason);
        $summaryFields = [
            [
                'label' => 'Awaiting',
                'value' => $awaitingLabel,
            ],
            [
                'label' => 'Started',
                'value' => AppDateFormatter::timelineOperatorRelative($waitingState->started_at)
                    ?? AppDateFormatter::timelineDatetime($waitingState->started_at)
                    ?? '—',
            ],
        ];

        if ($waitingState->customer_followup_sent_at !== null) {
            $summaryFields[] = [
                'label' => 'Reminder sent',
                'value' => AppDateFormatter::timelineOperatorRelative($waitingState->customer_followup_sent_at)
                    ?? AppDateFormatter::timelineDatetime($waitingState->customer_followup_sent_at)
                    ?? '—',
            ];
        }

        $summaryFields[] = [
            'label' => 'Status',
            'value' => $resolution['status'],
        ];

        $title = match ($resolution['kind']) {
            'auto_closed' => 'Closed automatically',
            'resolved' => 'Waiting resolved',
            default => 'Waiting for Customer',
        };

        return new TimelineEvent(
            type: TimelineEventType::Automation,
            occurredAt: $resolution['occurred_at'],
            title: $title,
            actor: TimelineActorPresenter::for($this->automationIdentity->automationActor())->normalizedActor(),
            dedupeKey: "waiting-lifecycle:{$waitingState->id}",
            summaryFields: $summaryFields,
            filterTags: ['support', 'customer'],
            contextLine: TimelineWaitingReasonPresenter::contextLine($waitingState->waiting_reason),
            indicatorVariant: $resolution['indicator'],
            storyKey: "waiting-lifecycle:{$waitingState->id}",
        );
    }

    /**
     * @return array{kind: string, status: string, occurred_at: \Illuminate\Support\Carbon, indicator: string}
     */
    private function resolveResolution(IncidentWaitingState $waitingState): array
    {
        if ($waitingState->isActive()) {
            return [
                'kind' => 'active',
                'status' => 'Active',
                'occurred_at' => $waitingState->started_at,
                'indicator' => 'warning',
            ];
        }

        $auditLog = AuditLog::query()
            ->where('auditable_type', (new Incident)->getMorphClass())
            ->where('auditable_id', $waitingState->incident_id)
            ->whereIn('event', [
                CustomerWaitingLifecycleService::EVENT_AUTO_CLOSED,
                CustomerWaitingLifecycleService::EVENT_LEGACY_CLEANUP_CLOSED,
                CustomerWaitingLifecycleService::EVENT_IDENTITY_RESOLVED,
            ])
            ->where('created_at', '>=', $waitingState->started_at)
            ->orderByDesc('created_at')
            ->first();

        if ($auditLog?->event === CustomerWaitingLifecycleService::EVENT_AUTO_CLOSED
            || $auditLog?->event === CustomerWaitingLifecycleService::EVENT_LEGACY_CLEANUP_CLOSED) {
            return [
                'kind' => 'auto_closed',
                'status' => 'Closed automatically',
                'occurred_at' => $waitingState->cleared_at ?? $auditLog->created_at ?? $waitingState->started_at,
                'indicator' => 'muted',
            ];
        }

        return [
            'kind' => 'resolved',
            'status' => 'Resolved by customer',
            'occurred_at' => $waitingState->cleared_at ?? $waitingState->started_at,
            'indicator' => 'success',
        ];
    }
}
