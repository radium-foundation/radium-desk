<?php

namespace App\Services\Operations;

use App\Enums\ServiceCaseSlaStatus;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Governs Ira teammate Telegram delivery during working hours.
 *
 * Assignment itself is never blocked — only Telegram delivery is deferred
 * when the recipient is outside their schedule unless an urgent exception applies.
 */
class IraNotificationPolicyService
{
    public function __construct(
        private readonly WorkCalendarService $workCalendarService,
    ) {}

    public function canNotifyNow(User $recipient, ?Incident $incident = null, ?Carbon $at = null): bool
    {
        $at ??= now();

        if ($this->isUrgentException($recipient, $incident, $at)) {
            return true;
        }

        return $this->workCalendarService->isEligibleForAssignment($recipient, $at);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function canNotifyNowWithContext(
        User $recipient,
        ?Incident $incident,
        array $context = [],
        ?Carbon $at = null,
    ): bool {
        if ($this->isExplicitUrgentContext($context)) {
            return true;
        }

        return $this->canNotifyNow($recipient, $incident, $at);
    }

    private function isUrgentException(User $recipient, ?Incident $incident, Carbon $at): bool
    {
        if ($incident === null) {
            return false;
        }

        if ($incident->high_priority) {
            return true;
        }

        return in_array($incident->slaStatus($at), [
            ServiceCaseSlaStatus::Warning,
            ServiceCaseSlaStatus::Overdue,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function isExplicitUrgentContext(array $context): bool
    {
        foreach (['urgent', 'escalation', 'force_notify'] as $key) {
            if (! empty($context[$key])) {
                return true;
            }
        }

        return false;
    }
}
