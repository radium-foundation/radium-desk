<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineEvent;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Services\CommunicationActions\CommunicationActionLifecycleAuditService;
use App\Services\Timeline\Mappers\CommunicationActionLifecycleTimelineEventMapper;
use Illuminate\Support\Collection;

/**
 * Prepared timeline source for communication action lifecycle events.
 *
 * Not registered in Customer360TimelineSourceRegistry until a later phase
 * to avoid duplicate notification entries alongside NotificationTimelineEventSource.
 */
class CommunicationActionLifecycleTimelineEventSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
        private readonly CommunicationActionLifecycleTimelineEventMapper $mapper,
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
            ->where('event', CommunicationActionLifecycleAuditService::EVENT)
            ->orderByDesc('created_at')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get()
            ->map(fn (AuditLog $auditLog): ?TimelineEvent => $this->mapper->fromAuditLog($auditLog))
            ->filter()
            ->values();
    }
}
