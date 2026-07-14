<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Services\MissingSerial\MissingSerialAutomationService;

class OrderIdentityLifecycleService
{
    public const IDENTITY_FIELDS = [
        'serial_number',
        'device_model',
        'device_model_id',
        'product_name',
    ];

    public function __construct(
        private readonly ServiceCaseAssignmentEligibilityService $assignmentEligibility,
        private readonly ServiceCaseAutomationMonitorService $automationMonitor,
        private readonly IncidentWaitingStateService $waitingStateService,
    ) {}

    /**
     * @param  list<string>  $changedFields
     */
    public function afterIdentityFieldsChanged(
        Order $order,
        User $actor,
        string $source,
        array $changedFields,
    ): void {
        if (! $this->hasIdentityFields($changedFields)) {
            return;
        }

        $this->afterIdentityChanged(
            order: $order,
            actor: $actor,
            source: $source,
            serialChanged: in_array('serial_number', $changedFields, true),
        );
    }

    /**
     * @param  list<string>  $fields
     */
    public function hasIdentityFields(array $fields): bool
    {
        return array_intersect($fields, self::IDENTITY_FIELDS) !== [];
    }

    public function afterOrderCreatedWithIdentity(Order $order, User $actor, string $source): void
    {
        if (! $this->orderHasIdentityFields($order)) {
            return;
        }

        $this->afterIdentityChanged(
            order: $order,
            actor: $actor,
            source: $source,
            serialChanged: filled(trim((string) $order->serial_number)),
        );
    }

    public function resolveActorForOrder(Order $order, ?User $explicit = null): User
    {
        if ($explicit !== null) {
            return $explicit;
        }

        if ($order->updated_by !== null) {
            $user = User::query()->find($order->updated_by);

            if ($user !== null) {
                return $user;
            }
        }

        $order->loadMissing('incidents.creator');

        return $this->automationMonitor->resolveAutomationActor($order->incidents->first()?->creator);
    }

    public function afterIdentityChanged(
        Order $order,
        User $actor,
        string $source,
        bool $serialChanged = false,
    ): void {
        $freshOrder = $order->fresh();

        if ($freshOrder === null) {
            return;
        }

        $this->assignmentEligibility->evaluateAssignmentEligibility($freshOrder, $actor);

        if ($this->assignmentEligibility->passesValidationForOrder($freshOrder)) {
            $this->automationMonitor->recordValidationPassed($freshOrder, $actor);
            $this->waitingStateService->clearIdentityCorrectionWaitingWhenValidationPasses(
                order: $freshOrder,
                actor: $actor,
                source: $source,
            );
        }

        if ($serialChanged) {
            app(MissingSerialAutomationService::class)->markCompletedIfApplicable($freshOrder, $source);
        }
    }

    private function orderHasIdentityFields(Order $order): bool
    {
        return filled(trim((string) $order->serial_number))
            || filled(trim((string) $order->device_model))
            || filled(trim((string) $order->product_name))
            || filled($order->device_model_id);
    }
}
