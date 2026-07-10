<?php

namespace App\Services\Customer360;

use App\Models\AuditLog;
use App\Models\BonvoiceCallEvent;
use App\Models\Incident;
use App\Services\Bonvoice\BonvoiceCustomerCallService;
use App\Support\BonvoiceCallStatuses;
use Illuminate\Support\Carbon;

class CustomerContactAttemptEvidenceService
{
    public const MANUAL_CALL_ATTEMPT_EVENT = 'service_case.manual_call_attempt';

    /** @var list<string> */
    private const UNREACHABLE_STATUSES = ['NOANSWER', 'NOINPUT'];

    public function __construct(
        private readonly BonvoiceCustomerCallService $bonvoiceCallService,
    ) {}

    public function hasEvidenceFor(Incident $incident): bool
    {
        return $this->hasBonvoiceUnreachableAttempt($incident)
            || $this->hasManualCallAttemptLog($incident);
    }

    public function hasBonvoiceUnreachableAttempt(Incident $incident): bool
    {
        $incident->loadMissing(['order', 'bonvoiceCallLinks.bonvoiceCallEvent']);

        foreach ($incident->bonvoiceCallLinks as $link) {
            if ($this->isUnreachableStatus($link->bonvoiceCallEvent?->status)
                && $this->occurredOnOrAfterIncident($link->bonvoiceCallEvent, $incident)) {
                return true;
            }
        }

        $phone = $incident->order?->customer_phone;

        if (! filled($phone)) {
            return false;
        }

        return $this->bonvoiceCallService
            ->dedupedCallsForCustomer($phone)
            ->contains(fn (BonvoiceCallEvent $event): bool => $this->isUnreachableStatus($event->status)
                && $this->occurredOnOrAfterIncident($event, $incident));
    }

    public function hasManualCallAttemptLog(Incident $incident): bool
    {
        return AuditLog::query()
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->where('event', self::MANUAL_CALL_ATTEMPT_EVENT)
            ->exists();
    }

    private function isUnreachableStatus(?string $status): bool
    {
        $normalized = BonvoiceCallStatuses::normalize($status);

        return $normalized !== null && in_array($normalized, self::UNREACHABLE_STATUSES, true);
    }

    private function occurredOnOrAfterIncident(?BonvoiceCallEvent $event, Incident $incident): bool
    {
        if ($event === null || $incident->created_at === null) {
            return $event !== null;
        }

        $occurredAt = $event->started_at ?? $event->updated_at;

        if (! $occurredAt instanceof Carbon) {
            return true;
        }

        return $occurredAt->greaterThanOrEqualTo($incident->created_at);
    }
}
