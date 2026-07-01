<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\InteraktDeliveryStatus;
use App\Enums\InteraktMessageDirection;
use App\Enums\TimelineEventType;
use App\Models\InteraktMessage;
use App\Models\Order;
use Illuminate\Support\Collection;

class WhatsAppTimelineEventSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
    ) {}

    public function collect(): Collection
    {
        if (! filled($this->order->customer_phone)) {
            return collect();
        }

        return InteraktMessage::query()
            ->where('customer_phone', $this->order->customer_phone)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (InteraktMessage $message): TimelineEvent => $this->mapMessage($message))
            ->values();
    }

    private function mapMessage(InteraktMessage $message): TimelineEvent
    {
        $occurredAt = $message->sent_at
            ?? $message->delivered_at
            ?? $message->read_at
            ?? $message->created_at;

        $statusLabel = $message->direction === InteraktMessageDirection::Outgoing
            ? $this->deliveryStatusLabel($message->delivery_status)
            : null;

        return new TimelineEvent(
            type: TimelineEventType::WhatsApp,
            occurredAt: $occurredAt,
            title: 'WhatsApp',
            actor: $this->resolveActor($message),
            dedupeKey: "whatsapp:{$message->message_id}",
            summary: $this->resolveSummary($message),
            detail: $this->resolveDetail($message),
            statusLabel: $statusLabel,
            statusVariant: $this->deliveryStatusVariant($message->delivery_status),
        );
    }

    private function resolveActor(InteraktMessage $message): TimelineActor
    {
        if ($message->direction === InteraktMessageDirection::Incoming) {
            return new TimelineActor('Customer');
        }

        if (filled($message->template_name)) {
            return new TimelineActor('Template', $message->template_name);
        }

        return new TimelineActor('Radium Desk');
    }

    private function resolveSummary(InteraktMessage $message): ?string
    {
        if (filled($message->text)) {
            return $message->text;
        }

        if (filled($message->template_name)) {
            return $message->template_name;
        }

        return null;
    }

    private function resolveDetail(InteraktMessage $message): ?string
    {
        if (! filled($message->media_url)) {
            return null;
        }

        return $message->media_url;
    }

    private function deliveryStatusLabel(?InteraktDeliveryStatus $status): ?string
    {
        return match ($status) {
            InteraktDeliveryStatus::Sent => 'Sent',
            InteraktDeliveryStatus::Delivered => 'Delivered',
            InteraktDeliveryStatus::Read => 'Read',
            InteraktDeliveryStatus::Failed => 'Failed',
            InteraktDeliveryStatus::Pending => 'Pending',
            default => null,
        };
    }

    private function deliveryStatusVariant(?InteraktDeliveryStatus $status): ?string
    {
        return match ($status) {
            InteraktDeliveryStatus::Sent => 'sent',
            InteraktDeliveryStatus::Delivered => 'delivered',
            InteraktDeliveryStatus::Read => 'read',
            InteraktDeliveryStatus::Failed => 'failed',
            InteraktDeliveryStatus::Pending => 'pending',
            default => null,
        };
    }
}
