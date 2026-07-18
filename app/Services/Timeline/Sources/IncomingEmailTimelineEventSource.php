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
use App\Support\AppDateFormatter;
use Illuminate\Support\Collection;

class IncomingEmailTimelineEventSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
    ) {}

    public function collect(?int $limit = null): Collection
    {
        $this->order->loadMissing('incidents');

        $incidentIds = $this->order->incidents->pluck('id')->filter()->values();
        $customerEmail = strtolower(trim((string) $this->order->customer_email));

        if ($incidentIds->isEmpty() && $customerEmail === '') {
            return collect();
        }

        $query = IncomingEmailMessage::query()
            ->where('status', IncomingEmailMessageStatus::Linked)
            ->where(function ($builder) use ($incidentIds, $customerEmail): void {
                if ($incidentIds->isNotEmpty()) {
                    $builder->whereIn('incident_id', $incidentIds);
                }

                if ($customerEmail !== '') {
                    if ($incidentIds->isNotEmpty()) {
                        $builder->orWhere('from_email', $customerEmail);
                    } else {
                        $builder->where('from_email', $customerEmail);
                    }
                }
            })
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
        $summaryFields = array_values(array_filter([
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
            filled($message->rfc_message_id) ? [
                'label' => 'Message ID',
                'value' => (string) $message->rfc_message_id,
            ] : null,
            [
                'label' => 'Attachments',
                'value' => (string) $message->attachment_count,
            ],
        ]));

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
            summary: $message->preview,
            detail: $message->preview,
            statusLabel: 'Linked',
            statusVariant: 'success',
            summaryFields: $summaryFields,
            filterTags: ['customer', 'notifications'],
        );
    }
}
