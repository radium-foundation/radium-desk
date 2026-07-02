<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Data\WhatsAppConversationSnapshot;
use App\Enums\TimelineEventType;
use App\Models\Order;
use App\Services\Interakt\InteraktDeepLinkService;
use App\Services\Interakt\WhatsAppConversationAggregator;
use App\Support\AppDateFormatter;
use Illuminate\Support\Collection;

class WhatsAppTimelineEventSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
        private readonly WhatsAppConversationAggregator $aggregator,
        private readonly InteraktDeepLinkService $deepLinkService,
    ) {}

    public function collect(?int $limit = null): Collection
    {
        if (! filled($this->order->customer_phone)) {
            return collect();
        }

        $snapshot = $this->aggregator->forPhone($this->order->customer_phone);

        if ($snapshot === null) {
            return collect();
        }

        return collect([$this->mapSnapshot($snapshot)]);
    }

    private function mapSnapshot(WhatsAppConversationSnapshot $snapshot): TimelineEvent
    {
        $summaryFields = [
            [
                'label' => 'Last Activity',
                'value' => AppDateFormatter::timelineDatetime($snapshot->lastActivityAt) ?? '—',
            ],
            [
                'label' => 'Messages',
                'value' => $snapshot->messagesExchangedCount.' exchanged',
            ],
            [
                'label' => 'Current Status',
                'value' => $snapshot->conversationStatus->label(),
            ],
        ];

        if (filled($snapshot->lastTemplateName)) {
            $summaryFields[] = [
                'label' => 'Template',
                'value' => $snapshot->lastTemplateName,
            ];
        }

        return new TimelineEvent(
            type: TimelineEventType::WhatsApp,
            occurredAt: $snapshot->lastActivityAt,
            title: 'WhatsApp',
            actor: $this->resolveActor($snapshot),
            dedupeKey: 'whatsapp:summary:'.$snapshot->customerPhone,
            statusLabel: $snapshot->conversationStatus->label(),
            statusVariant: $snapshot->conversationStatus->statusVariant(),
            summaryFields: $summaryFields,
            actionLabel: 'Open in Interakt',
            actionUrl: $this->deepLinkService->conversationUrl($snapshot),
        );
    }

    private function resolveActor(WhatsAppConversationSnapshot $snapshot): TimelineActor
    {
        return match ($snapshot->lastSender) {
            'customer' => new TimelineActor('Customer'),
            'template' => new TimelineActor('Template', $snapshot->lastTemplateName),
            default => new TimelineActor('Radium Desk'),
        };
    }
}
