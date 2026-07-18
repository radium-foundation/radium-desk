<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\TimelineActorKind;
use App\Enums\TimelineEventType;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Services\IncomingEmail\IncomingEmailOrderVisibilityQuery;
use App\Support\AppDateFormatter;
use Illuminate\Support\Collection;

class IncomingEmailTimelineEventSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
        private readonly IncomingEmailOrderVisibilityQuery $visibilityQuery,
    ) {}

    public function collect(?int $limit = null): Collection
    {
        $query = $this->visibilityQuery
            ->forOrder($this->order)
            ->orderByDesc('received_at')
            ->orderByDesc('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()
            ->map(fn (IncomingEmailMessage $message): TimelineEvent => $this->mapMessage($message))
            ->values();
    }

    private function mapMessage(IncomingEmailMessage $message): TimelineEvent
    {
        $occurredAt = $message->received_at ?? $message->created_at ?? now();
        $isHistorical = $message->status === IncomingEmailMessageStatus::HistoricalCustomer;
        $preview = $message->displayPreview();
        $attachmentNames = array_values(array_filter(array_map(
            static fn (array $attachment): ?string => filled($attachment['filename'] ?? null)
                ? (string) $attachment['filename']
                : null,
            $message->attachmentMetadata(),
        )));

        $summaryFields = array_values(array_filter([
            filled($preview) ? [
                'label' => 'Preview',
                'value' => (string) $preview,
            ] : null,
            filled($message->mailbox) ? [
                'label' => 'Mailbox',
                'value' => (string) $message->mailbox,
            ] : null,
            filled($message->from_email) ? [
                'label' => 'Sender',
                'value' => filled($message->from_name)
                    ? $message->from_name.' <'.$message->from_email.'>'
                    : (string) $message->from_email,
            ] : null,
            filled($message->subject) ? [
                'label' => 'Subject',
                'value' => (string) $message->subject,
            ] : null,
            [
                'label' => 'Received',
                'value' => AppDateFormatter::timelineDatetime($occurredAt) ?? '—',
            ],
            filled($message->thread_id) ? [
                'label' => 'Thread ID',
                'value' => (string) $message->thread_id,
            ] : null,
            filled($message->provider_message_id) ? [
                'label' => 'Gmail Message ID',
                'value' => (string) $message->provider_message_id,
            ] : null,
            filled($message->rfc_message_id) ? [
                'label' => 'Message ID',
                'value' => (string) $message->rfc_message_id,
            ] : null,
            $attachmentNames !== [] ? [
                'label' => 'Attachments',
                'value' => implode(', ', $attachmentNames),
            ] : null,
        ]));

        $orderId = $message->order_id ?? $this->order->id;

        return new TimelineEvent(
            type: TimelineEventType::Email,
            occurredAt: $occurredAt,
            title: 'Incoming Email',
            actor: new TimelineActor(
                displayName: filled($message->from_name)
                    ? (string) $message->from_name
                    : (string) $message->from_email,
                kind: TimelineActorKind::Customer,
            ),
            dedupeKey: 'incoming_email:'.$message->id,
            summary: $preview,
            detail: null,
            statusLabel: $isHistorical
                ? IncomingEmailMessageStatus::HistoricalCustomer->label()
                : 'Linked',
            statusVariant: $isHistorical ? 'warning' : 'success',
            summaryFields: $summaryFields,
            actionLabel: $isHistorical ? 'Create Service Case' : null,
            actionUrl: $isHistorical
                ? route('orders.service-cases.create', [
                    'order' => $orderId,
                    'incoming_email_message_id' => $message->id,
                ])
                : null,
            filterTags: ['customer', 'notifications', 'communication'],
            contextLine: $isHistorical
                ? 'Known customer with no active service case.'
                : null,
        );
    }
}
