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

        foreach ($this->collapseConsecutiveSchedulerRecoveries($auditLogs) as $item) {
            if ($item instanceof AuditLog) {
                $mapped = $this->mapAuditLog($item);

                if ($mapped !== null) {
                    $entries->push($mapped);
                }

                continue;
            }

            $entries->push($this->formatSchedulerRecoveryGroup($item['count'], $item['last_occurred_at']));
        }

        return $entries
            ->sortByDesc(fn (array $entry): int => $entry['occurred_at_timestamp'])
            ->take(8)
            ->values()
            ->map(fn (array $entry): array => array_diff_key($entry, ['occurred_at_timestamp' => true]))
            ->all();
    }

    /**
     * @return list<AuditLog|array{count: int, last_occurred_at: mixed}>
     */
    private function collapseConsecutiveSchedulerRecoveries(Collection $auditLogs): array
    {
        $sorted = $auditLogs->sortBy('created_at')->values();
        $output = [];
        $recoveryGroup = collect();

        foreach ($sorted as $auditLog) {
            if ($auditLog->event === RadiumBoxSyncAuditService::EVENT_SCHEDULER_RECOVERY) {
                $recoveryGroup->push($auditLog);

                continue;
            }

            if ($recoveryGroup->isNotEmpty()) {
                $output[] = $this->buildSchedulerRecoveryGroup($recoveryGroup);
                $recoveryGroup = collect();
            }

            $output[] = $auditLog;
        }

        if ($recoveryGroup->isNotEmpty()) {
            $output[] = $this->buildSchedulerRecoveryGroup($recoveryGroup);
        }

        return $output;
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @return array{count: int, last_occurred_at: mixed}
     */
    private function buildSchedulerRecoveryGroup(Collection $logs): array
    {
        return [
            'count' => $logs->count(),
            'last_occurred_at' => $logs->last()?->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSchedulerRecoveryGroup(int $count, mixed $occurredAt): array
    {
        $title = $count > 1
            ? "Scheduler Recovery ({$count} attempts)"
            : 'Scheduler Recovery';

        return $this->formatEntry(
            icon: 'scheduler',
            title: $title,
            occurredAt: $occurredAt,
            actorName: null,
            subtitle: $count > 1
                ? 'Last attempt: '.AppDateFormatter::format($occurredAt, 'd M h:i A')
                : null,
        );
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
        ?string $subtitle = null,
    ): array {
        return [
            'icon' => $icon,
            'title' => $title,
            'date' => AppDateFormatter::format($occurredAt, 'd M Y'),
            'time' => AppDateFormatter::format($occurredAt, 'h:i A'),
            'actor_name' => $actorName,
            'subtitle' => $subtitle,
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
