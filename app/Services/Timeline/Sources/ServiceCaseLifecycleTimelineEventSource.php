<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\TimelineActorKind;
use App\Enums\TimelineEventType;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Services\AutomationIdentityService;
use Illuminate\Support\Collection;

class ServiceCaseLifecycleTimelineEventSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
        private readonly AutomationIdentityService $automationIdentity,
    ) {}

    public function collect(?int $limit = null): Collection
    {
        $this->order->loadMissing('incidents');
        $incidentIds = $this->order->incidents->pluck('id');

        if ($incidentIds->isEmpty()) {
            return collect();
        }

        return AuditLog::query()
            ->with('user')
            ->where('auditable_type', (new Incident)->getMorphClass())
            ->whereIn('auditable_id', $incidentIds)
            ->whereIn('event', [
                'service_case.status_changed',
            ])
            ->orderByDesc('created_at')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get()
            ->map(function (AuditLog $auditLog): ?TimelineEvent {
                if ($auditLog->created_at === null) {
                    return null;
                }

                $newStatus = (string) ($auditLog->new_values['status'] ?? '');
                $title = match ($newStatus) {
                    'closed' => 'Incident closed',
                    default => 'Incident updated',
                };

                return new TimelineEvent(
                    type: TimelineEventType::ServiceCaseCreated,
                    occurredAt: $auditLog->created_at,
                    title: $title,
                    actor: $this->resolveActor($auditLog->user),
                    dedupeKey: "incident-status:{$auditLog->id}",
                    detail: $newStatus !== '' ? "Status: {$newStatus}" : null,
                    filterTags: ['support'],
                );
            })
            ->filter()
            ->values();
    }

    private function resolveActor($user): TimelineActor
    {
        $resolved = $this->automationIdentity->resolve($user);

        return new TimelineActor(
            displayName: $resolved->displayName,
            subtitle: $resolved->subtitle,
            isAutomation: $resolved->isAutomation,
            kind: $resolved->isAutomation ? TimelineActorKind::Automation : TimelineActorKind::Agent,
        );
    }
}
