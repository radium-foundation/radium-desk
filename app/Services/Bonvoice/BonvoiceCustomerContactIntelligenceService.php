<?php

namespace App\Services\Bonvoice;

use App\Data\Bonvoice\CustomerContactIntelligence;
use App\Models\BonvoiceCallEvent;
use App\Support\BonvoiceCallStatuses;
use Illuminate\Support\Carbon;

class BonvoiceCustomerContactIntelligenceService
{
    public function __construct(
        private readonly BonvoiceCustomerCallService $callService,
    ) {}

    public function forCustomerPhone(?string $phone, bool $hasActiveCase = false): ?CustomerContactIntelligence
    {
        $calls = $this->callService->dedupedCallsForCustomer($phone);

        if ($calls->isEmpty()) {
            return null;
        }

        $todayStart = today();
        $dayAgo = now()->subHours(24);

        $todayCalls = $calls->filter(
            fn (BonvoiceCallEvent $event): bool => $this->occurredAt($event)->greaterThanOrEqualTo($todayStart),
        );

        $missedToday = $todayCalls
            ->filter(fn (BonvoiceCallEvent $event): bool => BonvoiceCallStatuses::isMissedStatus($event->status))
            ->count();

        $answeredToday = $todayCalls
            ->filter(fn (BonvoiceCallEvent $event): bool => BonvoiceCallStatuses::isAnsweredStatus($event->status))
            ->count();

        $totalToday = $todayCalls->count();

        $lastCall = $calls->first();
        $lastContactAt = $lastCall !== null ? $this->occurredAt($lastCall) : null;

        $contactsLast24Hours = $calls
            ->filter(fn (BonvoiceCallEvent $event): bool => $this->occurredAt($event)->greaterThanOrEqualTo($dayAgo))
            ->count();

        $highUrgency = $hasActiveCase && $contactsLast24Hours >= 3;

        $summaryLine = $totalToday > 0
            ? sprintf(
                'Customer contacted %d time%s today: %d missed, %d answered. Last call %s.',
                $totalToday,
                $totalToday === 1 ? '' : 's',
                $missedToday,
                $answeredToday,
                $lastContactAt?->format('H:i') ?? '—',
            )
            : null;

        return new CustomerContactIntelligence(
            totalToday: $totalToday,
            missedToday: $missedToday,
            answeredToday: $answeredToday,
            lastContactAt: $lastContactAt,
            summaryLine: $summaryLine,
            contactsLast24Hours: $contactsLast24Hours,
            highUrgency: $highUrgency,
        );
    }

    private function occurredAt(BonvoiceCallEvent $event): Carbon
    {
        return $event->started_at ?? $event->updated_at ?? now();
    }
}
