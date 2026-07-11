<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\TimelineActorKind;
use App\Enums\TimelineEventType;
use App\Models\CustomerDataCorrection;
use App\Models\Order;
use Illuminate\Support\Collection;

class CustomerDataCorrectionTimelineEventSource implements TimelineEventSource
{
    private const FIELD_LABELS = [
        'customer_name' => 'Customer Name',
        'customer_phone' => 'Mobile Number',
        'customer_email' => 'Email Address',
    ];

    public function __construct(
        private readonly Order $order,
    ) {}

    public function collect(?int $limit = null): Collection
    {
        return CustomerDataCorrection::query()
            ->with(['correctedBy', 'items'])
            ->where('order_id', $this->order->id)
            ->orderByDesc('created_at')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get()
            ->map(fn (CustomerDataCorrection $correction): TimelineEvent => $this->mapCorrection($correction))
            ->values();
    }

    private function mapCorrection(CustomerDataCorrection $correction): TimelineEvent
    {
        $occurredAt = $correction->created_at ?? now();
        $actorName = trim((string) ($correction->correctedBy?->name ?? 'Agent'));
        $changes = $correction->items
            ->map(fn ($item): ?array => filled($item->field_name) ? [
                'label' => self::FIELD_LABELS[$item->field_name] ?? str_replace('_', ' ', ucfirst((string) $item->field_name)),
                'value' => sprintf(
                    '%s → %s',
                    $this->formatValue($item->old_value),
                    $this->formatValue($item->new_value),
                ),
            ] : null)
            ->filter()
            ->values()
            ->all();

        $detail = collect($changes)
            ->map(fn (array $change): string => "{$change['label']}: {$change['value']}")
            ->implode("\n");

        return new TimelineEvent(
            type: TimelineEventType::CustomerCorrection,
            occurredAt: $occurredAt,
            title: 'Customer details corrected',
            actor: new TimelineActor(
                displayName: $actorName,
                subtitle: 'Agent action',
                isAutomation: false,
                kind: TimelineActorKind::Agent,
            ),
            dedupeKey: 'customer-correction:'.$correction->id,
            summary: filled($correction->reason) ? (string) $correction->reason : null,
            detail: $detail !== '' ? $detail : null,
            summaryFields: $changes,
            filterTags: ['customer', 'support'],
        );
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '—';
        }

        return (string) $value;
    }
}
