<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\TimelineActorKind;
use App\Enums\TimelineEventType;
use App\Models\AuditLog;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Services\IncomingEmail\IncomingEmailOrderVisibilityQuery;
use App\Services\Timeline\IncomingEmailReopenTimelinePresenter;
use Illuminate\Support\Collection;

class IncomingEmailTimelineEventSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
        private readonly IncomingEmailOrderVisibilityQuery $visibilityQuery,
        private readonly IncomingEmailReopenTimelinePresenter $reopenPresenter,
    ) {}

    public function collect(?int $limit = null): Collection
    {
        $query = $this->visibilityQuery
            ->forOrder($this->order)
            ->with(['incident.order'])
            ->orderByDesc('received_at')
            ->orderByDesc('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $messages = $query->get();
        $indexes = $this->reopenPresenter->indexForMessages($messages);

        return $messages
            ->map(fn (IncomingEmailMessage $message): TimelineEvent => $this->mapMessage(
                $message,
                $indexes['reopens'][(int) $message->id] ?? null,
                $indexes['assignments'][(int) $message->id] ?? null,
            ))
            ->values();
    }

    private function mapMessage(
        IncomingEmailMessage $message,
        ?AuditLog $reopenAudit,
        ?AuditLog $assignmentAudit,
    ): TimelineEvent {
        $occurredAt = $message->received_at ?? $message->created_at ?? now();
        $isHistorical = $message->status === IncomingEmailMessageStatus::HistoricalCustomer;
        $preview = $message->displayPreview();
        $attachmentNames = array_values(array_filter(array_map(
            static fn (array $attachment): ?string => filled($attachment['filename'] ?? null)
                ? (string) $attachment['filename']
                : null,
            $message->attachmentMetadata(),
        )));

        $summaryFields = $this->reopenPresenter->displayFields($message);
        if ($attachmentNames !== []) {
            $summaryFields[] = [
                'label' => 'Attachments',
                'value' => implode(', ', $attachmentNames),
            ];
        }

        $isReopen = $reopenAudit instanceof AuditLog;
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
            contextLine: $isReopen
                ? IncomingEmailReopenTimelinePresenter::REOPEN_BODY
                : ($isHistorical ? 'Known customer with no active service case.' : null),
            storyKey: $isReopen ? IncomingEmailReopenTimelinePresenter::STORY_KEY : null,
            technicalFields: $this->reopenPresenter->technicalFields($message),
            actionBadges: $isReopen
                ? $this->reopenPresenter->actionBadges($message, $assignmentAudit)
                : [],
        );
    }
}
