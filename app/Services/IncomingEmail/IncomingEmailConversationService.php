<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncomingEmailMessageStatus;
use App\Enums\OutgoingEmailMessageStatus;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\OutgoingEmailMessage;
use App\Models\User;
use App\Services\OutgoingEmail\OutgoingEmailReplyGate;
use App\Services\OutgoingEmail\OutgoingEmailReplyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class IncomingEmailConversationService
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 100;

    public function __construct(
        private readonly OutgoingEmailReplyGate $replyGate,
        private readonly OutgoingEmailReplyService $replyService,
        private readonly IncomingEmailWorkspaceReadState $readState,
    ) {}

    /**
     * Lightweight meta for Communication section cards (no full thread page).
     *
     * @return array{
     *     last_customer_email_at: ?string,
     *     last_outgoing_email_at: ?string,
     *     unread_inbound_count: int,
     *     can_reply: bool,
     * }
     */
    public function headerMetaForIncident(Incident $incident, User $user): array
    {
        $latestInbound = self::inboundQuery($incident)
            ->select(['id', 'status', 'received_at', 'mailbox', 'thread_id', 'from_email', 'subject', 'incident_id', 'order_id'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();

        $latestOutbound = OutgoingEmailMessage::query()
            ->where(function ($query) use ($incident): void {
                $query->where('incident_id', $incident->id);

                if ($incident->order_id !== null) {
                    $query->orWhere('order_id', $incident->order_id);
                }
            })
            ->whereIn('status', [
                OutgoingEmailMessageStatus::Sent,
                OutgoingEmailMessageStatus::Failed,
                OutgoingEmailMessageStatus::Queued,
            ])
            ->orderByRaw('COALESCE(sent_at, created_at) desc')
            ->orderByDesc('id')
            ->first(['sent_at', 'created_at']);

        $replyTarget = self::inboundQuery($incident)
            ->where('status', IncomingEmailMessageStatus::Linked)
            ->select(['id', 'status', 'mailbox', 'thread_id', 'from_email', 'subject', 'incident_id', 'order_id', 'rfc_message_id'])
            ->orderByDesc('id')
            ->first();

        $canReply = false;
        if ($replyTarget instanceof IncomingEmailMessage) {
            $canReply = $this->replyGate->evaluate($user, $replyTarget)['allowed'];
        }

        return [
            'last_customer_email_at' => $latestInbound?->received_at?->toIso8601String(),
            'last_outgoing_email_at' => ($latestOutbound?->sent_at ?? $latestOutbound?->created_at)?->toIso8601String(),
            'unread_inbound_count' => $this->readState->unreadInboundCount($incident, $user),
            'can_reply' => $canReply,
        ];
    }

    /**
     * @param  array{
     *     limit?: int,
     *     before_at?: ?string,
     *     before_id?: ?int,
     *     before_direction?: ?string,
     *     since_at?: ?string,
     *     since_id?: ?int,
     *     since_direction?: ?string,
     * }  $options
     * @return array<string, mixed>
     */
    public function threadForIncident(Incident $incident, User $user, array $options = []): array
    {
        $limit = max(1, min(self::MAX_LIMIT, (int) ($options['limit'] ?? self::DEFAULT_LIMIT)));
        $before = $this->cursorFrom($options, 'before');
        $since = $this->cursorFrom($options, 'since');

        $inbound = $this->inboundMessages($incident);
        $outbound = $this->outboundMessages($incident);
        $merged = $this->mergeChronologically($inbound, $outbound);

        if ($since !== null) {
            $merged = array_values(array_filter(
                $merged,
                fn (array $row): bool => $this->isAfterCursor($row, $since),
            ));

            return $this->payload(
                incident: $incident,
                user: $user,
                inbound: $inbound,
                outbound: $outbound,
                pageMessages: array_map(static fn (array $row): array => $row['payload'], $merged),
                hasMoreOlder: false,
                isDelta: true,
            );
        }

        if ($before !== null) {
            $merged = array_values(array_filter(
                $merged,
                fn (array $row): bool => $this->isBeforeCursor($row, $before),
            ));
        }

        $hasMoreOlder = count($merged) > $limit;
        $page = $hasMoreOlder ? array_slice($merged, -$limit) : $merged;

        return $this->payload(
            incident: $incident,
            user: $user,
            inbound: $inbound,
            outbound: $outbound,
            pageMessages: array_map(static fn (array $row): array => $row['payload'], $page),
            hasMoreOlder: $hasMoreOlder,
            isDelta: false,
        );
    }

    /**
     * @return Builder<IncomingEmailMessage>
     */
    public static function inboundQuery(Incident $incident): Builder
    {
        return IncomingEmailMessage::query()
            ->where(function ($query) use ($incident): void {
                $query->where('incident_id', $incident->id);

                if ($incident->order_id !== null) {
                    $query->orWhere(function ($orderQuery) use ($incident): void {
                        $orderQuery
                            ->where('order_id', $incident->order_id)
                            ->where('status', IncomingEmailMessageStatus::HistoricalCustomer);
                    });
                }
            })
            ->whereIn('status', [
                IncomingEmailMessageStatus::Linked,
                IncomingEmailMessageStatus::HistoricalCustomer,
            ]);
    }

    /**
     * @param  Collection<int, IncomingEmailMessage>  $inbound
     * @param  Collection<int, OutgoingEmailMessage>  $outbound
     * @param  list<array<string, mixed>>  $pageMessages
     * @return array<string, mixed>
     */
    private function payload(
        Incident $incident,
        User $user,
        Collection $inbound,
        Collection $outbound,
        array $pageMessages,
        bool $hasMoreOlder,
        bool $isDelta,
    ): array {
        $replyTarget = $this->latestLinkedInbound($inbound);
        $canReply = false;
        $replyReason = 'no_linked_inbound';
        $defaultSubject = null;
        $toEmail = null;
        $mailbox = null;

        if ($replyTarget instanceof IncomingEmailMessage) {
            $gate = $this->replyGate->evaluate($user, $replyTarget);
            $canReply = $gate['allowed'];
            $replyReason = $gate['reason'];

            if ($canReply) {
                $context = $this->replyService->context($user, $replyTarget);
                $defaultSubject = $context['default_subject'];
                $toEmail = $context['to_email'];
                $mailbox = $context['mailbox'];
            }
        }

        $latestInbound = $inbound->sortByDesc(fn (IncomingEmailMessage $m): int => $m->id)->first();
        $latestOutbound = $outbound
            ->sortByDesc(fn (OutgoingEmailMessage $m): int => ($m->sent_at ?? $m->created_at)?->getTimestamp() ?? 0)
            ->first();

        $maxInboundId = $inbound
            ->filter(fn (IncomingEmailMessage $m): bool => $m->status === IncomingEmailMessageStatus::Linked)
            ->max('id');

        $incident->loadMissing(['order', 'assignee']);

        return [
            'messages' => $pageMessages,
            'has_more_older' => $hasMoreOlder,
            'is_delta' => $isDelta,
            'reply_to_incoming_email_message_id' => $replyTarget?->id,
            'can_reply' => $canReply,
            'reply_reason' => $replyReason,
            'default_subject' => $defaultSubject,
            'to_email' => $toEmail,
            'mailbox' => $mailbox,
            'customer_label' => $this->customerLabel($incident),
            'owner_label' => $incident->assignee?->name ?? 'Unassigned',
            'last_customer_email_at' => $latestInbound?->received_at?->toIso8601String(),
            'last_outgoing_email_at' => ($latestOutbound?->sent_at ?? $latestOutbound?->created_at)?->toIso8601String(),
            'unread_inbound_count' => $this->readState->unreadInboundCount($incident, $user),
            'latest_inbound_id' => $maxInboundId ? (int) $maxInboundId : null,
            'message_count' => count($pageMessages),
        ];
    }

    private function customerLabel(Incident $incident): string
    {
        $incident->loadMissing('order');
        $order = $incident->order;
        $name = trim((string) ($order?->customer_name ?? ''));
        if ($name !== '') {
            return $name;
        }
        $email = trim((string) ($order?->customer_email ?? ''));
        if ($email !== '') {
            return $email;
        }
        $phone = trim((string) ($order?->customer_phone ?? ''));

        return $phone !== '' ? $phone : 'Customer';
    }

    /**
     * @return Collection<int, IncomingEmailMessage>
     */
    private function inboundMessages(Incident $incident): Collection
    {
        return self::inboundQuery($incident)
            ->select([
                'id',
                'incident_id',
                'order_id',
                'mailbox',
                'thread_id',
                'rfc_message_id',
                'subject',
                'preview',
                'from_email',
                'from_name',
                'status',
                'received_at',
                'created_at',
            ])
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, OutgoingEmailMessage>
     */
    private function outboundMessages(Incident $incident): Collection
    {
        return OutgoingEmailMessage::query()
            ->where(function ($query) use ($incident): void {
                $query->where('incident_id', $incident->id);

                if ($incident->order_id !== null) {
                    $query->orWhere('order_id', $incident->order_id);
                }
            })
            ->whereIn('status', [
                OutgoingEmailMessageStatus::Sent,
                OutgoingEmailMessageStatus::Failed,
                OutgoingEmailMessageStatus::Queued,
            ])
            ->select([
                'id',
                'incident_id',
                'order_id',
                'subject',
                'preview',
                'body_text',
                'body_html',
                'to_email',
                'status',
                'sent_at',
                'created_at',
            ])
            ->orderByRaw('COALESCE(sent_at, created_at)')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, IncomingEmailMessage>  $inbound
     * @param  Collection<int, OutgoingEmailMessage>  $outbound
     * @return list<array{sort_at: int, sort_id: int, direction: string, payload: array<string, mixed>}>
     */
    private function mergeChronologically(Collection $inbound, Collection $outbound): array
    {
        $rows = [];

        foreach ($inbound as $message) {
            $rows[] = [
                'sort_at' => $message->received_at?->getTimestamp() ?? $message->created_at?->getTimestamp() ?? 0,
                'sort_id' => $message->id,
                'direction' => 'inbound',
                'payload' => [
                    'id' => $message->id,
                    'direction' => 'inbound',
                    'subject' => $message->subject,
                    'preview' => $message->preview,
                    'from_email' => $message->from_email,
                    'from_name' => $message->from_name,
                    'status' => $message->status?->value,
                    'status_label' => $message->status?->label(),
                    'occurred_at' => $message->received_at?->toIso8601String(),
                    'can_open' => true,
                ],
            ];
        }

        foreach ($outbound as $message) {
            $occurred = $message->sent_at ?? $message->created_at;
            $rows[] = [
                'sort_at' => $occurred?->getTimestamp() ?? 0,
                'sort_id' => $message->id,
                'direction' => 'outbound',
                'payload' => [
                    'id' => $message->id,
                    'direction' => 'outbound',
                    'subject' => $message->subject,
                    'preview' => $message->displayPreview(),
                    'to_email' => $message->to_email,
                    'status' => $message->status?->value,
                    'status_label' => $message->status?->label(),
                    'occurred_at' => $occurred?->toIso8601String(),
                    'can_open' => false,
                ],
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            if ($left['sort_at'] === $right['sort_at']) {
                if ($left['sort_id'] === $right['sort_id']) {
                    return strcmp($left['direction'], $right['direction']);
                }

                return $left['sort_id'] <=> $right['sort_id'];
            }

            return $left['sort_at'] <=> $right['sort_at'];
        });

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{at: int, id: int, direction: string}|null
     */
    private function cursorFrom(array $options, string $prefix): ?array
    {
        $at = $options[$prefix.'_at'] ?? null;
        $id = $options[$prefix.'_id'] ?? null;
        $direction = $options[$prefix.'_direction'] ?? null;

        if ($at === null || $at === '' || $id === null || $direction === null || $direction === '') {
            return null;
        }

        $timestamp = strtotime((string) $at);

        if ($timestamp === false) {
            return null;
        }

        return [
            'at' => $timestamp,
            'id' => (int) $id,
            'direction' => (string) $direction,
        ];
    }

    /**
     * @param  array{sort_at: int, sort_id: int, direction: string}  $row
     * @param  array{at: int, id: int, direction: string}  $cursor
     */
    private function isBeforeCursor(array $row, array $cursor): bool
    {
        if ($row['sort_at'] !== $cursor['at']) {
            return $row['sort_at'] < $cursor['at'];
        }

        if ($row['sort_id'] !== $cursor['id']) {
            return $row['sort_id'] < $cursor['id'];
        }

        return strcmp($row['direction'], $cursor['direction']) < 0;
    }

    /**
     * @param  array{sort_at: int, sort_id: int, direction: string}  $row
     * @param  array{at: int, id: int, direction: string}  $cursor
     */
    private function isAfterCursor(array $row, array $cursor): bool
    {
        if ($row['sort_at'] !== $cursor['at']) {
            return $row['sort_at'] > $cursor['at'];
        }

        if ($row['sort_id'] !== $cursor['id']) {
            return $row['sort_id'] > $cursor['id'];
        }

        return strcmp($row['direction'], $cursor['direction']) > 0;
    }

    /**
     * @param  Collection<int, IncomingEmailMessage>  $inbound
     */
    private function latestLinkedInbound(Collection $inbound): ?IncomingEmailMessage
    {
        return $inbound
            ->filter(fn (IncomingEmailMessage $message): bool => $message->status === IncomingEmailMessageStatus::Linked)
            ->sortByDesc(fn (IncomingEmailMessage $message): int => $message->id)
            ->first();
    }
}
