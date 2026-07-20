<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\MissingSerial\MissingSerialAutomationService;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        private readonly DeviceModelAliasResolver $deviceModelAliasResolver,
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

        $order = $this->attemptAutomaticDeviceModelAssignment(
            order: $order,
            actor: $actor,
            source: $source,
            changedFields: $changedFields,
        );

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

        $order = $this->attemptAutomaticDeviceModelAssignment(
            order: $order,
            actor: $actor,
            source: $source,
            changedFields: ['device_model'],
        );

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

        $freshOrder->loadMissing('incidents.activeBusinessHold');

        if ($freshOrder->incidents->contains(
            fn (Incident $incident): bool => $incident->isActive()
                && app(BusinessHoldService::class)->blocksLifecycleAdvancement($incident),
        )) {
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

    /**
     * @param  list<string>  $changedFields
     */
    private function attemptAutomaticDeviceModelAssignment(
        Order $order,
        User $actor,
        string $source,
        array $changedFields,
    ): Order {
        if (! in_array('device_model', $changedFields, true)) {
            return $order;
        }

        $freshOrder = $order->fresh();

        if ($freshOrder === null || $freshOrder->hasDeviceModelAssigned()) {
            return $order;
        }

        $rawModel = trim((string) $freshOrder->device_model);

        if ($rawModel === '') {
            return $order;
        }

        try {
            $deviceModel = $this->deviceModelAliasResolver->resolve($rawModel);

            if ($deviceModel === null) {
                return $order;
            }

            return app(OrderDeviceModelService::class)->assignDeviceModel(
                order: $freshOrder,
                deviceModel: $deviceModel,
                actor: $actor,
                isBulk: true,
            );
        } catch (Throwable $exception) {
            Log::warning('order_identity.device_model_alias_assignment_failed', [
                'order_id' => $freshOrder->order_id,
                'order_pk' => $freshOrder->id,
                'source' => $source,
                'device_model' => $rawModel,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $order;
        }
    }
}
