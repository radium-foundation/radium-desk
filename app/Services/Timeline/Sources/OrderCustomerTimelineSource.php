<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineEvent;
use App\Data\OrderCorrectionChange;
use App\Data\OrderTimelineEntry;
use App\Enums\TimelineEventType;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Remark;
use App\Services\AutomationIdentityService;
use App\Services\OrderActivityTimelineService;
use App\Services\ServiceCaseAutomationMonitorService;
use Illuminate\Support\Collection;

class OrderCustomerTimelineSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
        private readonly OrderActivityTimelineService $orderActivityTimelineService,
        private readonly AutomationIdentityService $automationIdentity,
    ) {}

    public function collect(): Collection
    {
        $events = collect();

        foreach ($this->paymentEvents() as $event) {
            $events->push($event);
        }

        foreach ($this->internalNoteEvents() as $event) {
            $events->push($event);
        }

        foreach ($this->orderActivityTimelineService->forOrder($this->order) as $entry) {
            if ($this->shouldSkipOrderActivityEntry($entry)) {
                continue;
            }

            $events->push($this->mapOrderActivityEntry($entry));
        }

        return $events;
    }

    /**
     * @return Collection<int, TimelineEvent>
     */
    private function paymentEvents(): Collection
    {
        $events = collect();

        if ($this->order->payment_date !== null) {
            $summary = $this->formatPaymentSummary();

            $events->push(new TimelineEvent(
                type: TimelineEventType::Payment,
                occurredAt: $this->order->payment_date,
                title: 'Payment received',
                actor: $this->automationIdentity->automationActor(),
                dedupeKey: "payment:order:{$this->order->id}",
                summary: $summary,
            ));
        }

        $this->order->loadMissing('incidents');
        $incidentIds = $this->order->incidents->pluck('id');

        if ($incidentIds->isEmpty()) {
            return $events;
        }

        $paymentAuditLogs = AuditLog::query()
            ->with('user')
            ->where('auditable_type', (new Incident)->getMorphClass())
            ->whereIn('auditable_id', $incidentIds)
            ->where('event', ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED)
            ->orderByDesc('created_at')
            ->get();

        foreach ($paymentAuditLogs as $auditLog) {
            if ($auditLog->created_at === null) {
                continue;
            }

            $events->push(new TimelineEvent(
                type: TimelineEventType::Payment,
                occurredAt: $auditLog->created_at,
                title: 'Payment received',
                actor: $this->automationIdentity->resolve($auditLog->user),
                dedupeKey: "payment:audit:{$auditLog->id}",
            ));
        }

        return $events;
    }

    /**
     * @return Collection<int, TimelineEvent>
     */
    private function internalNoteEvents(): Collection
    {
        $this->order->loadMissing('incidents');
        $incidentIds = $this->order->incidents->pluck('id');
        $refundIds = $this->order->refundRequests()->pluck('id');

        $remarks = Remark::query()
            ->with(['user', 'mentions.user'])
            ->where(function ($query) use ($incidentIds, $refundIds) {
                $query->where(function ($orderQuery) {
                    $orderQuery->where('remarkable_type', $this->order->getMorphClass())
                        ->where('remarkable_id', $this->order->id);
                });

                if ($incidentIds->isNotEmpty()) {
                    $query->orWhere(function ($incidentQuery) use ($incidentIds) {
                        $incidentQuery->where('remarkable_type', (new Incident)->getMorphClass())
                            ->whereIn('remarkable_id', $incidentIds);
                    });
                }

                if ($refundIds->isNotEmpty()) {
                    $query->orWhere(function ($refundQuery) use ($refundIds) {
                        $refundQuery->where('remarkable_type', (new RefundRequest)->getMorphClass())
                            ->whereIn('remarkable_id', $refundIds);
                    });
                }
            })
            ->orderByDesc('created_at')
            ->get();

        return $remarks
            ->filter(fn (Remark $remark) => $remark->created_at !== null)
            ->map(function (Remark $remark): TimelineEvent {
                $body = trim((string) $remark->body);
                $isLong = $body !== '' && mb_strlen($body) > TimelineEvent::DETAIL_COLLAPSE_THRESHOLD;

                return new TimelineEvent(
                    type: TimelineEventType::InternalNote,
                    occurredAt: $remark->created_at,
                    title: TimelineEvent::INTERNAL_NOTE_TITLE,
                    actor: $this->automationIdentity->resolve($remark->user),
                    dedupeKey: "remark:{$remark->id}",
                    summary: $body !== '' && ! $isLong ? $body : null,
                    detail: $isLong ? $body : null,
                    noteBody: $body !== '' ? $body : null,
                    mentionedUserNames: $remark->mentionedUserNames(),
                );
            });
    }

    private function shouldSkipOrderActivityEntry(OrderTimelineEntry $entry): bool
    {
        return str_contains(strtolower($entry->title), 'remark');
    }

    private function mapOrderActivityEntry(OrderTimelineEntry $entry): TimelineEvent
    {
        $type = $this->resolveType($entry);
        $summary = $entry->detail;
        $detail = $this->buildDetail($entry);

        if ($detail !== null) {
            $summary = null;
        }

        return new TimelineEvent(
            type: $type,
            occurredAt: $entry->occurredAt,
            title: $this->normalizeTitle($entry, $type),
            actor: $entry->actor,
            dedupeKey: $entry->dedupeKey,
            summary: $summary,
            detail: $detail,
        );
    }

    private function resolveType(OrderTimelineEntry $entry): TimelineEventType
    {
        $title = strtolower($entry->title);

        if (str_contains($title, 'service case') && str_contains($title, 'created')) {
            return TimelineEventType::ServiceCaseCreated;
        }

        if (str_contains($title, 'assigned') || str_contains($title, 'reassigned')) {
            return TimelineEventType::Assignment;
        }

        if (str_contains($title, 'remark')) {
            return TimelineEventType::InternalNote;
        }

        if (str_contains($title, 'payment') || str_contains($title, 'refund')) {
            return TimelineEventType::Payment;
        }

        return TimelineEventType::AuditEvent;
    }

    private function normalizeTitle(OrderTimelineEntry $entry, TimelineEventType $type): string
    {
        return match ($type) {
            TimelineEventType::ServiceCaseCreated => $entry->title,
            TimelineEventType::Assignment => $entry->title,
            TimelineEventType::InternalNote => TimelineEvent::INTERNAL_NOTE_TITLE,
            TimelineEventType::Payment => $entry->title,
            TimelineEventType::AuditEvent => $entry->title,
            default => $entry->title,
        };
    }

    private function buildDetail(OrderTimelineEntry $entry): ?string
    {
        if ($entry->correctionChanges === []) {
            return null;
        }

        $lines = array_map(
            fn (OrderCorrectionChange $change): string => "{$change->label}: {$change->previous} → {$change->next}",
            $entry->correctionChanges,
        );

        if ($entry->correctionReason !== null && $entry->correctionReason !== '') {
            $lines[] = $entry->correctionReason;
        }

        return implode("\n", $lines);
    }

    private function formatPaymentSummary(): ?string
    {
        $parts = [];

        if ($this->order->payment_amount !== null) {
            $parts[] = '₹'.number_format((float) $this->order->payment_amount, 2);
        }

        if (filled($this->order->payment_method)) {
            $parts[] = (string) $this->order->payment_method;
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
