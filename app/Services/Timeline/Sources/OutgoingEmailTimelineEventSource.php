<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\OutgoingEmailMessageStatus;
use App\Enums\TimelineActorKind;
use App\Enums\TimelineEventType;
use App\Models\Order;
use App\Models\OutgoingEmailMessage;
use App\Support\AppDateFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OutgoingEmailTimelineEventSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
    ) {}

    public function collect(?int $limit = null): Collection
    {
        $this->order->loadMissing('incidents');
        $incidentIds = $this->order->incidents->pluck('id')->filter()->values();

        $query = OutgoingEmailMessage::query()
            ->with('sentBy')
            ->where('status', OutgoingEmailMessageStatus::Sent)
            ->where(function (Builder $builder) use ($incidentIds): void {
                $builder->where('order_id', $this->order->id);

                if ($incidentIds->isNotEmpty()) {
                    $builder->orWhereIn('incident_id', $incidentIds);
                }
            })
            ->orderByDesc('sent_at')
            ->orderByDesc('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()
            ->map(fn (OutgoingEmailMessage $message): TimelineEvent => $this->mapMessage($message))
            ->values();
    }

    private function mapMessage(OutgoingEmailMessage $message): TimelineEvent
    {
        $occurredAt = $message->sent_at ?? $message->created_at ?? now();
        $actorName = $message->sentBy?->name ?? 'Support';

        return new TimelineEvent(
            type: TimelineEventType::OutgoingEmail,
            occurredAt: $occurredAt,
            title: 'Outgoing Email',
            actor: new TimelineActor(
                displayName: $actorName,
                kind: TimelineActorKind::Agent,
            ),
            dedupeKey: 'outgoing_email:'.$message->id,
            summary: $message->displayPreview(),
            detail: $message->subject,
            statusLabel: 'Sent',
            statusVariant: 'success',
            contextLine: filled($message->subject) ? (string) $message->subject : null,
            summaryFields: array_values(array_filter([
                filled($message->to_email) ? [
                    'label' => 'To',
                    'value' => (string) $message->to_email,
                ] : null,
                filled($message->mailbox) ? [
                    'label' => 'From',
                    'value' => (string) $message->mailbox,
                ] : null,
                $message->sent_at !== null ? [
                    'label' => 'Sent',
                    'value' => AppDateFormatter::timelineDatetime($message->sent_at) ?? '—',
                ] : null,
            ])),
            filterTags: ['customer', 'notifications', 'communication', 'support'],
            indicatorVariant: 'info',
        );
    }
}
