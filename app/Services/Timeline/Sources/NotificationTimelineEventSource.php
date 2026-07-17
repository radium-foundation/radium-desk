<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\Timeline\Mappers\NotificationTimelineEventMapper;
use Illuminate\Support\Collection;

class NotificationTimelineEventSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
        private readonly NotificationTimelineEventMapper $mapper,
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
                NotificationAuditTrailService::EVENT_DISPATCHED,
                NotificationAuditTrailService::EVENT_SKIPPED,
            ])
            ->orderByDesc('created_at')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get()
            ->flatMap(fn (AuditLog $auditLog): Collection => $this->mapper->fromAuditLog($auditLog))
            ->values();
    }
}
