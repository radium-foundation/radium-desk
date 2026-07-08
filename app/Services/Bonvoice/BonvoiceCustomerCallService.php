<?php

namespace App\Services\Bonvoice;

use App\Models\BonvoiceCallEvent;
use App\Support\AppDateFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BonvoiceCustomerCallService
{
    /**
     * @return Collection<int, BonvoiceCallEvent>
     */
    public function dedupedCallsForCustomer(?string $customerPhone): Collection
    {
        if (! filled($customerPhone)) {
            return collect();
        }

        return BonvoiceCallEvent::query()
            ->where('customer_phone', $customerPhone)
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy('call_id')
            ->map(fn (Collection $legs) => $legs->sortByDesc(fn (BonvoiceCallEvent $event) => $event->updated_at?->timestamp ?? 0)->first())
            ->filter()
            ->sortByDesc(fn (BonvoiceCallEvent $event) => $event->started_at?->timestamp ?? $event->updated_at?->timestamp ?? 0)
            ->values();
    }

    public function latestCallForCustomer(?string $customerPhone): ?BonvoiceCallEvent
    {
        return $this->dedupedCallsForCustomer($customerPhone)->first();
    }

    /**
     * @return array{status_label: string, occurred_at: Carbon, occurred_at_label: string}|null
     */
    public function lastCallSummary(?string $customerPhone): ?array
    {
        $event = $this->latestCallForCustomer($customerPhone);

        if ($event === null) {
            return null;
        }

        $occurredAt = $event->started_at ?? $event->updated_at ?? now();
        $statusLabel = trim((string) ($event->status ?? ''));

        return [
            'status_label' => $statusLabel !== '' ? strtoupper($statusLabel) : 'UNKNOWN',
            'occurred_at' => $occurredAt,
            'occurred_at_label' => AppDateFormatter::timelineRelative($occurredAt) ?? '—',
        ];
    }
}
