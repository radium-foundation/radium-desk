<?php

namespace App\Services\RadiumBox;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\ServiceCaseAutomationMonitorService;
use App\Support\AppDateFormatter;
use Illuminate\Support\Collection;

class RadiumBoxSyncTimelineService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forOrder(Order $order): array
    {
        $order->loadMissing('incidents.creator');
        $incidentIds = $order->incidents->pluck('id');

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
            ])
            ->latest('created_at')
            ->limit(10)
            ->get();

        $entries = collect();

        if ($order->created_at !== null) {
            $entries->push($this->formatEntry(
                icon: 'order',
                title: 'Order Created',
                occurredAt: $order->created_at,
                actorName: null,
            ));
        }

        foreach ($auditLogs as $auditLog) {
            $mapped = $this->mapAuditLog($auditLog);

            if ($mapped !== null) {
                $entries->push($mapped);
            }
        }

        return $entries
            ->sortByDesc(fn (array $entry): int => $entry['occurred_at_timestamp'])
            ->take(8)
            ->values()
            ->map(fn (array $entry): array => array_diff_key($entry, ['occurred_at_timestamp' => true]))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapAuditLog(AuditLog $auditLog): ?array
    {
        $occurredAt = $auditLog->created_at;

        if ($occurredAt === null) {
            return null;
        }

        return match ($auditLog->event) {
            ServiceCaseAutomationMonitorService::EVENT_RADIUMBOX_VERIFIED => $this->formatEntry(
                icon: 'success',
                title: 'Background Sync Completed',
                occurredAt: $occurredAt,
                actorName: null,
            ),
            RadiumBoxSyncAuditService::EVENT_MANUAL_SYNC => $this->formatEntry(
                icon: 'manual',
                title: 'Manual Retry',
                occurredAt: $occurredAt,
                actorName: $this->resolveActorName($auditLog->user),
            ),
            RadiumBoxSyncAuditService::EVENT_SCHEDULER_RECOVERY => $this->formatEntry(
                icon: 'scheduler',
                title: 'Scheduler Recovery',
                occurredAt: $occurredAt,
                actorName: null,
            ),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEntry(
        string $icon,
        string $title,
        mixed $occurredAt,
        ?string $actorName,
    ): array {
        return [
            'icon' => $icon,
            'title' => $title,
            'date' => AppDateFormatter::format($occurredAt, 'd M Y'),
            'time' => AppDateFormatter::format($occurredAt, 'h:i A'),
            'actor_name' => $actorName,
            'occurred_at_timestamp' => $occurredAt?->getTimestamp() ?? 0,
        ];
    }

    private function resolveActorName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        return $user->firstName() ?: $user->name;
    }
}
