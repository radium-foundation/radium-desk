<?php

namespace App\Services\Timeline\Sources;

use App\Contracts\Timeline\TimelineEventSource;
use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\TimelineActorKind;
use App\Enums\TimelineEventType;
use App\Models\BonvoiceCallEvent;
use App\Models\Order;
use App\Services\Bonvoice\BonvoiceAgentResolver;
use App\Services\Bonvoice\BonvoiceCustomerCallService;
use App\Support\AppDateFormatter;
use Illuminate\Support\Collection;

class BonVoiceCallTimelineEventSource implements TimelineEventSource
{
    public function __construct(
        private readonly Order $order,
        private readonly BonvoiceCustomerCallService $bonvoiceCustomerCallService,
        private readonly BonvoiceAgentResolver $bonvoiceAgentResolver,
    ) {}

    public function collect(?int $limit = null): Collection
    {
        $events = $this->bonvoiceCustomerCallService->dedupedCallsForCustomer($this->order->customer_phone);

        if ($limit !== null) {
            $events = $events->take($limit);
        }

        return $events
            ->map(fn (BonvoiceCallEvent $event): TimelineEvent => $this->mapCallEvent($event))
            ->values();
    }

    private function mapCallEvent(BonvoiceCallEvent $event): TimelineEvent
    {
        $occurredAt = $event->started_at ?? $event->updated_at ?? now();
        $directionLabel = $this->directionLabel($event->direction);
        $agentName = $this->bonvoiceAgentResolver->resolveAgentFirstNameForCall($event);
        $duration = $this->formatDuration($this->callDuration($event));
        $summaryFields = array_values(array_filter([
            filled($agentName) ? [
                'label' => 'Agent',
                'value' => $agentName,
            ] : null,
            filled($duration) ? [
                'label' => 'Duration',
                'value' => $duration,
            ] : null,
            filled($event->status) ? [
                'label' => 'Status',
                'value' => strtoupper((string) $event->status),
            ] : null,
            filled($event->agent_status) ? [
                'label' => 'Agent Status',
                'value' => $event->agent_status,
            ] : null,
            filled($event->recording_url) ? [
                'label' => 'Recording',
                'value' => 'Available',
            ] : null,
            [
                'label' => 'Started',
                'value' => AppDateFormatter::timelineDatetime($occurredAt) ?? '—',
            ],
        ]));

        return new TimelineEvent(
            type: TimelineEventType::IvrCall,
            occurredAt: $occurredAt,
            title: $directionLabel.' Call',
            actor: $this->resolveActor($agentName),
            dedupeKey: 'bonvoice:call:'.$event->call_id,
            statusLabel: $event->status,
            statusVariant: $this->statusVariant($event->status),
            summaryFields: $summaryFields,
            actionLabel: filled($event->recording_url) ? 'Play Recording' : null,
            actionUrl: $event->recording_url,
            filterTags: ['customer', 'notifications'],
        );
    }

    private function resolveActor(?string $agentName): TimelineActor
    {
        if (filled($agentName)) {
            return new TimelineActor(
                displayName: 'Customer → '.$agentName,
                kind: TimelineActorKind::Customer,
            );
        }

        return new TimelineActor(
            displayName: 'Customer',
            kind: TimelineActorKind::Customer,
        );
    }

    private function callDuration(BonvoiceCallEvent $event): ?string
    {
        $payload = $event->payload ?? [];

        return is_array($payload)
            ? ($payload['CallDuration'] ?? $payload['duration_seconds'] ?? null)
            : null;
    }

    private function formatDuration(mixed $seconds): ?string
    {
        if (! is_scalar($seconds) || trim((string) $seconds) === '' || ! is_numeric($seconds)) {
            return null;
        }

        $total = (int) $seconds;

        if ($total < 60) {
            return $total.'s';
        }

        $minutes = intdiv($total, 60);
        $remainingSeconds = $total % 60;

        return $remainingSeconds > 0
            ? "{$minutes}m {$remainingSeconds}s"
            : "{$minutes}m";
    }

    private function directionLabel(?string $direction): string
    {
        $normalized = strtolower((string) $direction);

        return match (true) {
            in_array($normalized, ['inbound', 'in', 'incoming'], true) => 'Inbound',
            in_array($normalized, ['outbound', 'out', 'outgoing'], true) => 'Outbound',
            default => 'IVR',
        };
    }

    private function statusVariant(?string $status): string
    {
        $normalized = strtolower((string) $status);

        return match (true) {
            str_contains($normalized, 'answer'),
            str_contains($normalized, 'complete'),
            str_contains($normalized, 'connected') => 'success',
            str_contains($normalized, 'miss'),
            str_contains($normalized, 'fail'),
            str_contains($normalized, 'busy'),
            str_contains($normalized, 'reject') => 'danger',
            str_contains($normalized, 'ring'),
            str_contains($normalized, 'queue'),
            str_contains($normalized, 'hold') => 'pending',
            default => 'warning',
        };
    }
}
