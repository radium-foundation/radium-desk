<?php

namespace App\Services\Timeline\Mappers;

use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\TimelineActorKind;
use App\Enums\TimelineEventType;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AutomationIdentityService;
use App\Services\RadiumBox\RadiumBoxSyncAuditService;
use App\Services\ServiceCaseAutomationMonitorService;
use Illuminate\Support\Collection;

class RadiumBoxSyncTimelineEventMapper
{
    public function __construct(
        private readonly AutomationIdentityService $automationIdentity,
    ) {}

    /**
     * @return Collection<int, TimelineEvent>
     */
    public function forOrder(Order $order): Collection
    {
        $order->loadMissing('incidents.creator');
        $incidentIds = $order->incidents->pluck('id');
        $events = collect();

        if ($order->created_at !== null) {
            $events->push(new TimelineEvent(
                type: TimelineEventType::Synchronization,
                occurredAt: $order->created_at,
                title: 'Order created',
                actor: $this->systemActor(),
                dedupeKey: "order-created:{$order->id}",
                filterTags: ['synchronization', 'system'],
            ));
        }

        $auditLogs = AuditLog::query()
            ->with('user')
            ->where(function ($query) use ($order, $incidentIds): void {
                $query->where(function ($orderQuery) use ($order): void {
                    $orderQuery->where('auditable_type', $order->getMorphClass())
                        ->where('auditable_id', $order->id);
                });

                if ($incidentIds->isNotEmpty()) {
                    $query->orWhere(function ($incidentQuery) use ($incidentIds): void {
                        $incidentQuery->where('auditable_type', (new Incident)->getMorphClass())
                            ->whereIn('auditable_id', $incidentIds)
                            ->whereIn('event', [
                                ServiceCaseAutomationMonitorService::EVENT_RADIUMBOX_VERIFIED,
                                ServiceCaseAutomationMonitorService::EVENT_WAITING_RADIUMBOX,
                            ]);
                    });
                }
            })
            ->whereIn('event', [
                RadiumBoxSyncAuditService::EVENT_MANUAL_SYNC,
                RadiumBoxSyncAuditService::EVENT_SCHEDULER_RECOVERY,
                ServiceCaseAutomationMonitorService::EVENT_RADIUMBOX_VERIFIED,
                ServiceCaseAutomationMonitorService::EVENT_WAITING_RADIUMBOX,
                'serial.assigned',
                'device-model.assigned',
            ])
            ->latest('created_at')
            ->limit(25)
            ->get();

        foreach ($auditLogs as $auditLog) {
            $mapped = $this->mapAuditLog($auditLog);

            if ($mapped !== null) {
                $events->push($mapped);
            }
        }

        if (filled($order->radiumbox_last_sync_error) && $order->radiumbox_last_sync_at !== null) {
            $events->push(new TimelineEvent(
                type: TimelineEventType::Synchronization,
                occurredAt: $order->radiumbox_last_sync_at,
                title: 'RadiumBox sync failed',
                actor: $this->systemActor(),
                dedupeKey: "radiumbox-failed:{$order->id}:{$order->radiumbox_last_sync_at->timestamp}",
                detail: $order->radiumbox_last_sync_error,
                statusLabel: 'Failed',
                statusVariant: 'danger',
                filterTags: ['synchronization', 'system'],
            ));
        }

        return $events;
    }

    private function mapAuditLog(AuditLog $auditLog): ?TimelineEvent
    {
        if ($auditLog->created_at === null) {
            return null;
        }

        return match ($auditLog->event) {
            ServiceCaseAutomationMonitorService::EVENT_WAITING_RADIUMBOX => new TimelineEvent(
                type: TimelineEventType::Synchronization,
                occurredAt: $auditLog->created_at,
                title: 'Background sync started',
                actor: $this->automationActor(),
                dedupeKey: "radiumbox-waiting:{$auditLog->id}",
                filterTags: ['synchronization', 'system'],
            ),
            ServiceCaseAutomationMonitorService::EVENT_RADIUMBOX_VERIFIED => new TimelineEvent(
                type: TimelineEventType::Synchronization,
                occurredAt: $auditLog->created_at,
                title: 'Background sync completed',
                actor: $this->automationActor(),
                dedupeKey: "radiumbox-verified:{$auditLog->id}",
                statusLabel: 'Completed',
                statusVariant: 'success',
                filterTags: ['synchronization', 'system'],
            ),
            RadiumBoxSyncAuditService::EVENT_MANUAL_SYNC => new TimelineEvent(
                type: TimelineEventType::Synchronization,
                occurredAt: $auditLog->created_at,
                title: (($auditLog->new_values['success'] ?? false) === true)
                    ? 'Manual retry succeeded'
                    : 'Manual retry',
                actor: $this->agentActor($auditLog->user),
                dedupeKey: "radiumbox-manual:{$auditLog->id}",
                detail: $auditLog->new_values['error_message'] ?? null,
                statusLabel: (($auditLog->new_values['success'] ?? false) === true) ? 'Completed' : 'Failed',
                statusVariant: (($auditLog->new_values['success'] ?? false) === true) ? 'success' : 'danger',
                filterTags: ['synchronization', 'support'],
            ),
            RadiumBoxSyncAuditService::EVENT_SCHEDULER_RECOVERY => new TimelineEvent(
                type: TimelineEventType::Synchronization,
                occurredAt: $auditLog->created_at,
                title: 'Recovery retry dispatched',
                actor: $this->systemActor(),
                dedupeKey: "radiumbox-recovery:{$auditLog->id}",
                filterTags: ['synchronization', 'system'],
            ),
            'serial.assigned' => new TimelineEvent(
                type: TimelineEventType::Synchronization,
                occurredAt: $auditLog->created_at,
                title: 'Serial assigned',
                actor: $this->resolveActor($auditLog->user),
                dedupeKey: "serial-assigned:{$auditLog->id}",
                detail: (string) ($auditLog->new_values['serial_number'] ?? ''),
                filterTags: ['synchronization'],
            ),
            'device-model.assigned' => new TimelineEvent(
                type: TimelineEventType::Synchronization,
                occurredAt: $auditLog->created_at,
                title: 'Device model updated',
                actor: $this->resolveActor($auditLog->user),
                dedupeKey: "device-model-assigned:{$auditLog->id}",
                detail: (string) ($auditLog->new_values['device_model'] ?? ''),
                filterTags: ['synchronization'],
            ),
            default => null,
        };
    }

    private function systemActor(): TimelineActor
    {
        return new TimelineActor(
            displayName: 'System',
            kind: TimelineActorKind::System,
        );
    }

    private function automationActor(): TimelineActor
    {
        return new TimelineActor(
            displayName: $this->automationIdentity->automationActor()->displayName,
            subtitle: $this->automationIdentity->automationActor()->subtitle,
            isAutomation: true,
            kind: TimelineActorKind::Automation,
        );
    }

    private function agentActor(?User $user): TimelineActor
    {
        $resolved = $this->automationIdentity->resolve($user);

        return new TimelineActor(
            displayName: $resolved->displayName,
            subtitle: $resolved->subtitle,
            isAutomation: $resolved->isAutomation,
            kind: $resolved->isAutomation ? TimelineActorKind::Automation : TimelineActorKind::Agent,
        );
    }

    private function resolveActor(?User $user): TimelineActor
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
