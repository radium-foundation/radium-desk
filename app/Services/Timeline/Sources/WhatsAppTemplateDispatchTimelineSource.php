<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\TimelineEventType;
use App\Enums\WhatsAppTemplateDispatchStatus;
use App\Models\Order;
use App\Models\WhatsAppTemplateDispatch;
use Illuminate\Support\Collection;

class WhatsAppTemplateDispatchTimelineSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
    ) {}

    public function collect(?int $limit = null): Collection
    {
        return WhatsAppTemplateDispatch::query()
            ->where('order_id', $this->order->id)
            ->whereIn('status', [
                WhatsAppTemplateDispatchStatus::Sent,
                WhatsAppTemplateDispatchStatus::Failed,
            ])
            ->orderByDesc('dispatched_at')
            ->orderByDesc('id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get()
            ->map(fn (WhatsAppTemplateDispatch $dispatch): TimelineEvent => $this->mapDispatch($dispatch))
            ->values();
    }

    private function mapDispatch(WhatsAppTemplateDispatch $dispatch): TimelineEvent
    {
        $occurredAt = $dispatch->dispatched_at ?? $dispatch->created_at;

        return new TimelineEvent(
            type: TimelineEventType::WhatsAppTemplateSent,
            occurredAt: $occurredAt,
            title: 'WhatsApp Template Sent',
            actor: new TimelineActor(
                $dispatch->trigger_source->label(),
            ),
            dedupeKey: 'whatsapp-template-dispatch:'.$dispatch->id,
            statusLabel: $dispatch->status->timelineStatusLabel(),
            statusVariant: $dispatch->status->statusVariant(),
            summaryFields: [
                [
                    'label' => 'Template',
                    'value' => $dispatch->template_display_name,
                ],
                [
                    'label' => 'Purpose',
                    'value' => $dispatch->template_purpose,
                ],
                [
                    'label' => 'Trigger',
                    'value' => $dispatch->trigger_source->label(),
                ],
            ],
        );
    }
}
