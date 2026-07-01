<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineEvent;
use App\Enums\TimelineEventType;
use App\Models\Order;
use App\Models\WhatsAppCommunicationSummary;
use App\Services\Interakt\InteraktDeepLinkService;
use App\Services\Interakt\WhatsAppCommunicationSummaryStore;
use App\Support\AppDateFormatter;
use Illuminate\Support\Collection;

class WhatsAppTimelineEventSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
        private readonly WhatsAppCommunicationSummaryStore $summaryStore,
        private readonly InteraktDeepLinkService $deepLinkService,
    ) {}

    public function collect(): Collection
    {
        if (! filled($this->order->customer_phone)) {
            return collect();
        }

        $summary = WhatsAppCommunicationSummary::query()
            ->where('customer_phone', $this->order->customer_phone)
            ->first();

        if ($summary === null) {
            $summary = $this->summaryStore->refreshForPhone($this->order->customer_phone);
        }

        if ($summary === null || $summary->last_activity_at === null) {
            return collect();
        }

        return collect([$this->mapSummary($summary)]);
    }

    private function mapSummary(WhatsAppCommunicationSummary $summary): TimelineEvent
    {
        $summaryFields = [
            [
                'label' => 'Last Activity',
                'value' => AppDateFormatter::timelineDatetime($summary->last_activity_at) ?? '—',
            ],
            [
                'label' => 'Messages',
                'value' => $summary->messages_exchanged_count.' exchanged',
            ],
            [
                'label' => 'Current Status',
                'value' => $summary->conversation_status->label(),
            ],
        ];

        if (filled($summary->last_template_name)) {
            $summaryFields[] = [
                'label' => 'Template',
                'value' => $summary->last_template_name,
            ];
        }

        if ($summary->unread_count !== null && $summary->unread_count > 0) {
            $summaryFields[] = [
                'label' => 'Unread',
                'value' => (string) $summary->unread_count,
            ];
        }

        return new TimelineEvent(
            type: TimelineEventType::WhatsApp,
            occurredAt: $summary->last_activity_at,
            title: 'WhatsApp',
            actor: $this->resolveActor($summary),
            dedupeKey: 'whatsapp:summary:'.$summary->customer_phone,
            statusLabel: $summary->conversation_status->label(),
            statusVariant: $summary->conversation_status->statusVariant(),
            summaryFields: $summaryFields,
            actionLabel: 'Open in Interakt',
            actionUrl: $this->deepLinkService->conversationUrl($summary),
        );
    }

    private function resolveActor(WhatsAppCommunicationSummary $summary): \App\Data\TimelineActor
    {
        return match ($summary->last_sender) {
            'customer' => new \App\Data\TimelineActor('Customer'),
            'template' => new \App\Data\TimelineActor('Template', $summary->last_template_name),
            default => new \App\Data\TimelineActor('Radium Desk'),
        };
    }
}
