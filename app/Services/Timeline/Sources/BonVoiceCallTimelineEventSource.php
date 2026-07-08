<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\TimelineActorKind;
use App\Enums\TimelineEventType;
use App\Models\BonvoiceCallEvent;
use App\Models\Order;
use App\Support\AppDateFormatter;
use Illuminate\Support\Collection;

class BonVoiceCallTimelineEventSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
    ) {}

    public function collect(?int $limit = null): Collection
    {
        if (! filled($this->order->customer_phone)) {
            return collect();
        }

        $events = BonvoiceCallEvent::query()
            ->where('customer_phone', $this->order->customer_phone)
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy('call_id')
            ->map(fn (Collection $legs) => $legs->sortByDesc(fn (BonvoiceCallEvent $event) => $event->updated_at?->timestamp ?? 0)->first())
            ->filter()
            ->sortByDesc(fn (BonvoiceCallEvent $event) => $event->started_at?->timestamp ?? $event->updated_at?->timestamp ?? 0)
            ->values();

        if ($limit !== null) {
            $events = $events->take($limit);
        }

        return $events
            ->map(fn (BonvoiceCallEvent $event): TimelineEvent => $this->mapCallEvent($event))
            ->values();
    }

    private function mapCallEvent(BonvoiceCallEvent $event): TimelineEvent
    {
        $occurredAt = $event->started_at ?? $event->updated_at ?? now();
        $directionLabel = $this->directionLabel($event->direction);
        $summaryFields = array_values(array_filter([
            [
                'label' => 'Direction',
                'value' => $directionLabel,
            ],
            filled($event->status) ? [
                'label' => 'Status',
                'value' => $event->status,
            ] : null,
            filled($event->agent_status) ? [
                'label' => 'Agent Status',
                'value' => $event->agent_status,
            ] : null,
            filled($event->source_number) ? [
                'label' => 'From',
                'value' => $event->source_number,
            ] : null,
            filled($event->destination_number) ? [
                'label' => 'To',
                'value' => $event->destination_number,
            ] : null,
            filled($event->call_type) ? [
                'label' => 'Call Type',
                'value' => $event->call_type,
            ] : null,
            [
                'label' => 'Started',
                'value' => AppDateFormatter::timelineDatetime($occurredAt) ?? '—',
            ],
        ]));

        return new TimelineEvent(
            type: TimelineEventType::IvrCall,
            occurredAt: $occurredAt,
            title: $directionLabel.' Call',
            actor: new TimelineActor(
                displayName: 'Customer',
                kind: TimelineActorKind::Customer,
            ),
            dedupeKey: 'bonvoice:call:'.$event->call_id,
            statusLabel: $event->status,
            statusVariant: $this->statusVariant($event->status),
            summaryFields: $summaryFields,
            filterTags: ['customer', 'notifications'],
        );
    }

    private function directionLabel(?string $direction): string
    {
        $normalized = strtolower((string) $direction);

        return match (true) {
            in_array($normalized, ['inbound', 'in', 'incoming'], true) => 'Inbound',
            in_array($normalized, ['outbound', 'out', 'outgoing'], true) => 'Outbound',
            default => 'IVR',
        };
    }

    private function statusVariant(?string $status): string
    {
        $normalized = strtolower((string) $status);

        return match (true) {
            str_contains($normalized, 'answer'),
            str_contains($normalized, 'complete'),
            str_contains($normalized, 'connected') => 'success',
            str_contains($normalized, 'miss'),
            str_contains($normalized, 'fail'),
            str_contains($normalized, 'busy'),
            str_contains($normalized, 'reject') => 'danger',
            str_contains($normalized, 'ring'),
            str_contains($normalized, 'queue'),
            str_contains($normalized, 'hold') => 'pending',
            default => 'warning',
        };
    }
}
