<?php

namespace App\Services;

use App\Data\ServiceCaseTimelineEntry;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Remark;
use App\Models\User;
use Illuminate\Support\Collection;

class ServiceCaseActivityTimelineService
{
    public function forIncident(Incident $incident): Collection
    {
        $incident->loadMissing(['creator', 'assignee']);

        $entries = collect();

        if ($incident->created_at !== null) {
            $entries->push(new ServiceCaseTimelineEntry(
                occurredAt: $incident->created_at,
                type: ServiceCaseTimelineEntry::TYPE_CREATED,
                actorName: $incident->creator?->firstName(),
                title: 'Created Service Case',
                body: null,
                remark: null,
                dedupeKey: "incident-created:{$incident->id}",
            ));
        }

        $remarks = Remark::query()
            ->with('user')
            ->where('remarkable_type', $incident->getMorphClass())
            ->where('remarkable_id', $incident->getKey())
            ->orderBy('created_at')
            ->get();

        foreach ($remarks as $remark) {
            if ($remark->created_at === null) {
                continue;
            }

            $entries->push(new ServiceCaseTimelineEntry(
                occurredAt: $remark->created_at,
                type: ServiceCaseTimelineEntry::TYPE_REMARK,
                actorName: $remark->user?->firstName(),
                title: '',
                body: $remark->body,
                remark: $remark,
                dedupeKey: "remark:{$remark->id}",
            ));
        }

        $auditLogs = AuditLog::query()
            ->with('user')
            ->where(function ($query) use ($incident) {
                $query->where(function ($incidentQuery) use ($incident) {
                    $incidentQuery->where('auditable_type', $incident->getMorphClass())
                        ->where('auditable_id', $incident->getKey());
                })->orWhere(function ($remarkQuery) use ($incident) {
                    $remarkQuery->where('auditable_type', (new Remark)->getMorphClass())
                        ->where('event', 'deleted')
                        ->where('old_values->remarkable_type', $incident->getMorphClass())
                        ->where('old_values->remarkable_id', $incident->getKey());
                });
            })
            ->orderBy('created_at')
            ->get();

        foreach ($auditLogs as $auditLog) {
            $entry = $this->mapAuditLogEntry($auditLog, $incident);

            if ($entry !== null) {
                $entries->push($entry);
            }
        }

        return $entries
            ->unique(fn (ServiceCaseTimelineEntry $entry) => $entry->dedupeKey)
            ->sortBy(fn (ServiceCaseTimelineEntry $entry) => $entry->occurredAt->timestamp)
            ->values();
    }

    private function mapAuditLogEntry(AuditLog $auditLog, Incident $incident): ?ServiceCaseTimelineEntry
    {
        $actorName = $auditLog->user?->firstName();
        $occurredAt = $auditLog->created_at ?? now();

        if ($auditLog->auditable_type === $incident->getMorphClass()) {
            return match ($auditLog->event) {
                'service_case.assigned' => new ServiceCaseTimelineEntry(
                    occurredAt: $occurredAt,
                    type: ServiceCaseTimelineEntry::TYPE_ASSIGNMENT,
                    actorName: $actorName,
                    title: 'Assigned to '.$this->assigneeFirstName($auditLog->new_values['assigned_to_user_id'] ?? null, $incident),
                    body: null,
                    remark: null,
                    dedupeKey: "audit:{$auditLog->id}",
                ),
                'service_case.reassigned' => new ServiceCaseTimelineEntry(
                    occurredAt: $occurredAt,
                    type: ServiceCaseTimelineEntry::TYPE_ASSIGNMENT,
                    actorName: $actorName,
                    title: 'Reassigned to '.$this->assigneeFirstName($auditLog->new_values['assigned_to_user_id'] ?? null, $incident),
                    body: null,
                    remark: null,
                    dedupeKey: "audit:{$auditLog->id}",
                ),
                'service_case.status_changed' => $this->mapStatusChangeEntry($auditLog, $actorName, $occurredAt),
                default => null,
            };
        }

        if ($auditLog->auditable_type === (new Remark)->getMorphClass() && $auditLog->event === 'deleted') {
            return new ServiceCaseTimelineEntry(
                occurredAt: $occurredAt,
                type: ServiceCaseTimelineEntry::TYPE_REMARK_DELETED,
                actorName: $actorName,
                title: 'Remark deleted',
                body: null,
                remark: null,
                dedupeKey: "audit:{$auditLog->id}",
            );
        }

        return null;
    }

    private function mapStatusChangeEntry(AuditLog $auditLog, ?string $actorName, $occurredAt): ServiceCaseTimelineEntry
    {
        $oldStatus = $this->statusLabel($auditLog->old_values['status'] ?? null);
        $newStatus = $this->statusLabel($auditLog->new_values['status'] ?? null);
        $newValue = (string) ($auditLog->new_values['status'] ?? '');

        $title = match ($newValue) {
            IncidentStatus::Closed->value => 'Closed Service Case',
            IncidentStatus::Resolved->value => $oldStatus !== null
                ? "Status: {$oldStatus} → Resolved"
                : 'Service case resolved',
            default => $oldStatus !== null && $newStatus !== null
                ? "Status: {$oldStatus} → {$newStatus}"
                : 'Status updated',
        };

        return new ServiceCaseTimelineEntry(
            occurredAt: $occurredAt,
            type: ServiceCaseTimelineEntry::TYPE_STATUS,
            actorName: $actorName,
            title: $title,
            body: null,
            remark: null,
            dedupeKey: "audit:{$auditLog->id}",
        );
    }

    private function statusLabel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $status = IncidentStatus::tryFrom((string) $value);

        return $status?->label();
    }

    private function assigneeFirstName(mixed $userId, Incident $incident): string
    {
        if ($userId) {
            $user = User::query()->find($userId);

            if ($user !== null) {
                return $user->firstName();
            }
        }

        return $incident->assignee?->firstName() ?? 'Admin';
    }
}
