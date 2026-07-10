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
use App\Services\SerialValidation\RequestCorrectSerialAuditService;
use Illuminate\Support\Collection;

class CorrectSerialRequestTimelineEventSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
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
            ->where('event', RequestCorrectSerialAuditService::EVENT_REQUEST_SENT)
            ->orderByDesc('created_at')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get()
            ->map(fn (AuditLog $auditLog): TimelineEvent => $this->mapAuditLog($auditLog))
            ->filter()
            ->values();
    }

    private function mapAuditLog(AuditLog $auditLog): ?TimelineEvent
    {
        if ($auditLog->created_at === null) {
            return null;
        }

        $actorName = trim((string) ($auditLog->new_values['sent_by'] ?? $auditLog->user?->name ?? 'Agent'));
        $oldSerial = (string) ($auditLog->new_values['old_serial'] ?? $auditLog->old_values['serial_number'] ?? '');
        $reason = (string) ($auditLog->new_values['reason'] ?? '');
        $confidence = (string) ($auditLog->new_values['confidence'] ?? '');

        return new TimelineEvent(
            type: TimelineEventType::Notification,
            occurredAt: $auditLog->created_at,
            title: 'Serial correction requested',
            actor: new TimelineActor(
                displayName: $actorName,
                subtitle: 'Agent action',
                isAutomation: false,
                kind: TimelineActorKind::Agent,
            ),
            dedupeKey: 'serial-correction:audit:'.$auditLog->id,
            detail: 'Asked customer to confirm the correct device serial number.',
            statusLabel: 'Sent',
            statusVariant: 'success',
            summaryFields: array_values(array_filter([
                filled($oldSerial) ? ['label' => 'Previous serial', 'value' => $oldSerial] : null,
                filled($confidence) ? ['label' => 'Confidence', 'value' => $confidence] : null,
                filled($reason) ? ['label' => 'Reason', 'value' => $reason] : null,
            ])),
            filterTags: ['notifications', 'serial'],
        );
    }
}
