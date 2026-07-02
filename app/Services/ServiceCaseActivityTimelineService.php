<?php

namespace App\Services;

use App\Data\NotificationDispatchResult;
use App\Data\NotificationResult;
use App\Data\ServiceCaseTimelineEntry;
use App\Data\TimelineActor;
use App\Enums\NotificationChannelType;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Remark;
use App\Models\ServiceCaseCloseException;
use App\Models\User;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\Notifications\NotificationDeliverySummaryFormatter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Collection;

class ServiceCaseActivityTimelineService
{
    public function __construct(
        private readonly AutomationIdentityService $automationIdentity,
        private readonly NotificationDeliverySummaryFormatter $deliverySummaryFormatter,
    ) {}

    public function forIncident(Incident $incident): Collection
    {
        $incident->loadMissing(['creator', 'assignee']);

        $entries = collect();

        if ($incident->created_at !== null) {
            $entries->push(new ServiceCaseTimelineEntry(
                occurredAt: $incident->created_at,
                type: ServiceCaseTimelineEntry::TYPE_CREATED,
                actor: $this->automationIdentity->resolve($incident->creator),
                title: 'Created Service Case',
                body: null,
                remark: null,
                dedupeKey: "incident-created:{$incident->id}",
            ));
        }

        $remarks = Remark::query()
            ->with(['user', 'mentions.user'])
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
                actor: $this->automationIdentity->resolve($remark->user),
                title: 'Internal Note',
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
                })->orWhere(function ($orderQuery) use ($incident) {
                    $orderQuery->where('auditable_type', (new Order)->getMorphClass())
                        ->where('auditable_id', $incident->order_id)
                        ->where('event', 'serial.corrected_by_ira');
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
        $actor = $this->automationIdentity->resolve($auditLog->user);
        $occurredAt = $auditLog->created_at ?? now();

        if ($auditLog->auditable_type === $incident->getMorphClass()) {
            return match ($auditLog->event) {
                'service_case.assigned' => $this->mapAssignedEntry($auditLog, $incident, $actor, $occurredAt),
                'service_case.reassigned' => new ServiceCaseTimelineEntry(
                    occurredAt: $occurredAt,
                    type: ServiceCaseTimelineEntry::TYPE_ASSIGNMENT,
                    actor: $actor,
                    title: ($auditLog->new_values['reason'] ?? null) === ServiceCaseAssignmentEligibilityService::AUTOMATIC_REASSIGNMENT_REASON
                        ? 'Automatically reassigned to Shift Admin'
                        : 'Reassigned to '.$this->assigneeFirstName($auditLog->new_values['assigned_to_user_id'] ?? null, $incident),
                    body: ($auditLog->new_values['reason'] ?? null) === ServiceCaseAssignmentEligibilityService::AUTOMATIC_REASSIGNMENT_REASON
                        ? 'Automatically reassigned after successful validation.'
                        : null,
                    remark: null,
                    dedupeKey: "audit:{$auditLog->id}",
                ),
                'service_case.automation_pending' => new ServiceCaseTimelineEntry(
                    occurredAt: $occurredAt,
                    type: ServiceCaseTimelineEntry::TYPE_ASSIGNMENT,
                    actor: $actor,
                    title: 'Automation pending ('.((int) ($auditLog->new_values['grace_period_seconds'] ?? 60)).' seconds)',
                    body: null,
                    remark: null,
                    dedupeKey: "audit:{$auditLog->id}",
                ),
                ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED => new ServiceCaseTimelineEntry(
                    occurredAt: $occurredAt,
                    type: ServiceCaseTimelineEntry::TYPE_STATUS,
                    actor: $this->automationIdentity->automationActor(),
                    title: 'Payment received',
                    body: null,
                    remark: null,
                    dedupeKey: "audit:{$auditLog->id}",
                ),
                ServiceCaseAutomationMonitorService::EVENT_WAITING_RADIUMBOX => new ServiceCaseTimelineEntry(
                    occurredAt: $occurredAt,
                    type: ServiceCaseTimelineEntry::TYPE_STATUS,
                    actor: $this->automationIdentity->automationActor(),
                    title: 'Waiting for RadiumBox',
                    body: null,
                    remark: null,
                    dedupeKey: "audit:{$auditLog->id}",
                ),
                ServiceCaseAutomationMonitorService::EVENT_RADIUMBOX_VERIFIED => new ServiceCaseTimelineEntry(
                    occurredAt: $occurredAt,
                    type: ServiceCaseTimelineEntry::TYPE_STATUS,
                    actor: $this->automationIdentity->automationActor(),
                    title: 'RadiumBox verification successful',
                    body: null,
                    remark: null,
                    dedupeKey: "audit:{$auditLog->id}",
                ),
                ServiceCaseAutomationMonitorService::EVENT_VALIDATION_PASSED => new ServiceCaseTimelineEntry(
                    occurredAt: $occurredAt,
                    type: ServiceCaseTimelineEntry::TYPE_STATUS,
                    actor: $this->automationIdentity->automationActor(),
                    title: 'Serial validation successful',
                    body: null,
                    remark: null,
                    dedupeKey: "audit:{$auditLog->id}",
                ),
                ServiceCaseAutomationMonitorService::EVENT_VALIDATION_FAILED => new ServiceCaseTimelineEntry(
                    occurredAt: $occurredAt,
                    type: ServiceCaseTimelineEntry::TYPE_STATUS,
                    actor: $this->automationIdentity->automationActor(),
                    title: 'Validation failed',
                    body: null,
                    remark: null,
                    dedupeKey: "audit:{$auditLog->id}",
                ),
                ServiceCaseAutomationMonitorService::EVENT_WAITING_MANUAL_CORRECTION => new ServiceCaseTimelineEntry(
                    occurredAt: $occurredAt,
                    type: ServiceCaseTimelineEntry::TYPE_STATUS,
                    actor: $this->automationIdentity->automationActor(),
                    title: 'Waiting for manual correction',
                    body: null,
                    remark: null,
                    dedupeKey: "audit:{$auditLog->id}",
                ),
                NotificationAuditTrailService::EVENT_DISPATCHED => $this->mapNotificationDispatchedEntry(
                    $auditLog,
                    $actor,
                    $occurredAt,
                ),
                'service_case.status_changed' => $this->mapStatusChangeEntry($auditLog, $incident, $actor, $occurredAt),
                'service_case.close_exception' => null,
                default => null,
            };
        }

        if ($auditLog->auditable_type === (new Order)->getMorphClass()
            && $auditLog->event === 'serial.corrected_by_ira') {
            return new ServiceCaseTimelineEntry(
                occurredAt: $occurredAt,
                type: ServiceCaseTimelineEntry::TYPE_STATUS,
                actor: $this->automationIdentity->automationActor(),
                title: 'Corrected by IRA',
                body: sprintf(
                    '%s → %s',
                    (string) ($auditLog->old_values['serial_number'] ?? '—'),
                    (string) ($auditLog->new_values['serial_number'] ?? '—'),
                ),
                remark: null,
                dedupeKey: "audit:{$auditLog->id}",
            );
        }

        if ($auditLog->auditable_type === (new Remark)->getMorphClass() && $auditLog->event === 'deleted') {
            return new ServiceCaseTimelineEntry(
                occurredAt: $occurredAt,
                type: ServiceCaseTimelineEntry::TYPE_REMARK_DELETED,
                actor: $actor,
                title: 'Remark deleted',
                body: null,
                remark: null,
                dedupeKey: "audit:{$auditLog->id}",
            );
        }

        return null;
    }

    private function mapNotificationDispatchedEntry(
        AuditLog $auditLog,
        TimelineActor $actor,
        $occurredAt,
    ): ServiceCaseTimelineEntry {
        $channelResults = collect($auditLog->new_values['channel_results'] ?? [])
            ->map(function (array $record): NotificationResult {
                $channel = NotificationChannelType::tryFrom((string) ($record['channel'] ?? ''));

                if ($channel === null) {
                    return NotificationResult::failure(
                        channel: NotificationChannelType::WhatsApp,
                        message: (string) ($record['message'] ?? 'Unknown channel result.'),
                        metadata: ['status' => (string) ($record['status'] ?? 'failed')],
                    );
                }

                $success = (bool) ($record['success'] ?? false);
                $metadata = ['status' => (string) ($record['status'] ?? ($success ? 'sent' : 'failed'))];

                return $success
                    ? NotificationResult::success(
                        channel: $channel,
                        message: $record['message'] ?? null,
                        metadata: $metadata,
                    )
                    : NotificationResult::failure(
                        channel: $channel,
                        message: (string) ($record['message'] ?? 'Delivery failed.'),
                        retryable: (bool) ($record['retryable'] ?? false),
                        metadata: $metadata,
                    );
            })
            ->values()
            ->all();

        $aggregateSuccess = (bool) ($auditLog->new_values['aggregate_success'] ?? false);
        $summary = $this->deliverySummaryFormatter->format(new NotificationDispatchResult(
            success: $aggregateSuccess,
            results: $channelResults,
            message: $auditLog->new_values['aggregate_message'] ?? null,
        ));

        return new ServiceCaseTimelineEntry(
            occurredAt: $occurredAt,
            type: ServiceCaseTimelineEntry::TYPE_STATUS,
            actor: $actor,
            title: $aggregateSuccess ? 'Notification sent' : 'Notification failed',
            body: $summary,
            remark: null,
            dedupeKey: "audit:{$auditLog->id}",
        );
    }

    private function mapAssignedEntry(
        AuditLog $auditLog,
        Incident $incident,
        TimelineActor $actor,
        $occurredAt,
    ): ServiceCaseTimelineEntry {
        $assigneeId = $auditLog->new_values['assigned_to_user_id'] ?? null;
        $assignee = $assigneeId ? User::query()->find($assigneeId) : null;
        $isRoundRobinAgent = $assignee !== null
            && $assignee->hasRole(RolePermissionSeeder::ROLE_AGENT)
            && ! $assignee->hasAnyRole([
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ]);

        return new ServiceCaseTimelineEntry(
            occurredAt: $occurredAt,
            type: ServiceCaseTimelineEntry::TYPE_ASSIGNMENT,
            actor: $actor,
            title: $isRoundRobinAgent
                ? 'Assigned to Agent (Round Robin)'
                : 'Assigned to '.$this->assigneeFirstName($assigneeId, $incident),
            body: null,
            remark: null,
            dedupeKey: "audit:{$auditLog->id}",
        );
    }

    private function mapStatusChangeEntry(
        AuditLog $auditLog,
        Incident $incident,
        TimelineActor $actor,
        $occurredAt,
    ): ServiceCaseTimelineEntry {
        $oldStatus = $this->statusLabel($auditLog->old_values['status'] ?? null);
        $newStatus = $this->statusLabel($auditLog->new_values['status'] ?? null);
        $newValue = (string) ($auditLog->new_values['status'] ?? '');

        if ($newValue === IncidentStatus::Closed->value) {
            return $this->mapClosedEntry($auditLog, $incident, $actor, $occurredAt);
        }

        if ($newValue === IncidentStatus::Open->value
            && ($auditLog->old_values['status'] ?? null) === IncidentStatus::Closed->value) {
            return new ServiceCaseTimelineEntry(
                occurredAt: $occurredAt,
                type: ServiceCaseTimelineEntry::TYPE_STATUS,
                actor: $actor,
                title: 'Service case reopened.',
                body: null,
                remark: null,
                dedupeKey: "audit:{$auditLog->id}",
            );
        }

        $title = match ($newValue) {
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
            actor: $actor,
            title: $title,
            body: null,
            remark: null,
            dedupeKey: "audit:{$auditLog->id}",
        );
    }

    private function mapClosedEntry(
        AuditLog $auditLog,
        Incident $incident,
        TimelineActor $actor,
        $occurredAt,
    ): ServiceCaseTimelineEntry {
        $exceptions = ServiceCaseCloseException::query()
            ->where('incident_id', $incident->id)
            ->whereBetween('created_at', [
                $occurredAt->copy()->subSeconds(10),
                $occurredAt->copy()->addSeconds(2),
            ])
            ->orderBy('id')
            ->get();

        $body = null;

        if ($exceptions->isNotEmpty()) {
            $lines = $exceptions->map(function ($exception) {
                $label = $exception->serial_number_unavailable
                    ? 'Serial Number'
                    : 'Reference Number';

                return "{$label}:\n{$exception->exception_id}\n\nReason:\n{$exception->displayReason()}";
            });

            $body = $lines->implode("\n\n");
        }

        return new ServiceCaseTimelineEntry(
            occurredAt: $occurredAt,
            type: ServiceCaseTimelineEntry::TYPE_STATUS,
            actor: $actor,
            title: 'Service case closed',
            body: $body,
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
