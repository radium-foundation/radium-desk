<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class ServiceCaseAutomationMonitorService
{
    public const EVENT_PAYMENT_RECEIVED = 'service_case.automation.payment_received';

    public const EVENT_WAITING_RADIUMBOX = 'service_case.automation.waiting_radiumbox';

    public const EVENT_RADIUMBOX_VERIFIED = 'service_case.automation.radiumbox_verified';

    public const EVENT_VALIDATION_PASSED = 'service_case.automation.validation_passed';

    public const EVENT_VALIDATION_FAILED = 'service_case.automation.validation_failed';

    public const EVENT_WAITING_MANUAL_CORRECTION = 'service_case.automation.waiting_manual_correction';

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly AutomationIdentityService $automationIdentity,
    ) {}

    public function recordPaymentReceived(Incident $incident, User $actor): void
    {
        $this->recordOnce(
            incident: $incident,
            event: self::EVENT_PAYMENT_RECEIVED,
            actor: $actor,
            newValues: [
                'source' => $incident->source?->value,
            ],
        );
    }

    public function recordWaitingRadiumBox(Order $order, User $actor): void
    {
        $this->recordForOrderIncidents(
            order: $order,
            event: self::EVENT_WAITING_RADIUMBOX,
            actor: $actor,
        );
    }

    public function recordRadiumBoxVerified(Order $order, User $actor): void
    {
        $this->recordForOrderIncidents(
            order: $order,
            event: self::EVENT_RADIUMBOX_VERIFIED,
            actor: $actor,
        );
    }

    public function recordValidationPassed(Order $order, User $actor): void
    {
        $this->recordForOrderIncidents(
            order: $order,
            event: self::EVENT_VALIDATION_PASSED,
            actor: $actor,
        );
    }

    public function recordValidationFailed(Incident $incident, User $actor): void
    {
        $this->recordOnce(
            incident: $incident,
            event: self::EVENT_VALIDATION_FAILED,
            actor: $actor,
        );
    }

    public function recordWaitingManualCorrection(Incident $incident, User $actor): void
    {
        $this->recordOnce(
            incident: $incident,
            event: self::EVENT_WAITING_MANUAL_CORRECTION,
            actor: $actor,
        );
    }

    public function resolveAutomationActor(?User $fallback = null): User
    {
        $systemEmail = (string) config('cashfree.system_user_email');

        if ($systemEmail === '') {
            return $fallback ?? $this->automationIdentity->systemUser();
        }

        $userId = Cache::remember(
            'automation.monitor.actor_id.'.$systemEmail,
            now()->addDay(),
            function () use ($systemEmail, $fallback): int {
                $systemUserId = User::query()->where('email', $systemEmail)->value('id');

                return $systemUserId ?? $fallback?->id ?? $this->automationIdentity->systemUser()->id;
            },
        );

        $user = User::query()->find($userId);

        if ($user instanceof User) {
            return $user;
        }

        if ($fallback instanceof User) {
            return $fallback;
        }

        return $this->automationIdentity->systemUser();
    }

    private function recordForOrderIncidents(Order $order, string $event, User $actor): void
    {
        Incident::query()
            ->where('order_id', $order->id)
            ->where('status', '!=', IncidentStatus::Closed)
            ->orderBy('id')
            ->each(function (Incident $incident) use ($event, $actor): void {
                $this->recordOnce(
                    incident: $incident,
                    event: $event,
                    actor: $actor,
                );
            });
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function recordOnce(Incident $incident, string $event, User $actor, array $newValues = []): void
    {
        $alreadyRecorded = AuditLog::query()
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->where('event', $event)
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        $this->auditLogService->log(
            userId: $this->resolveAutomationActor($actor)->id,
            event: $event,
            auditable: $incident,
            oldValues: [],
            newValues: $newValues,
        );
    }
}
