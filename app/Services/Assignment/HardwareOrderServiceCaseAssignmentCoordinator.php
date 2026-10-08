<?php

namespace App\Services\Assignment;

use App\Enums\AssignmentOrigin;
use App\Models\HardwareFulfilment;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\ServiceCaseOrderAssignmentRoutingService;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Support\Facades\Log;

/**
 * Routes paid hardware product orders awaiting internal serial allocation away
 * from generic support round-robin and toward hardware fulfilment operators.
 */
class HardwareOrderServiceCaseAssignmentCoordinator
{
    public const AUDIT_DEFERRED = 'service_case.hardware_routing_deferred';

    public const AUDIT_UNRESOLVED = 'service_case.hardware_routing_unresolved';

    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly ServiceCaseOrderAssignmentRoutingService $orderRouting,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function isAwaitingInternalSerialAllocation(?Order $order): bool
    {
        if ($order === null) {
            return false;
        }

        if (! Order::isHardwareOrderId($order->order_id)) {
            return false;
        }

        return ! filled(trim((string) $order->serial_number));
    }

    public function shouldBlockSupportRoundRobin(Incident $incident): bool
    {
        $incident->loadMissing('order');

        return $this->isAwaitingInternalSerialAllocation($incident->order);
    }

    /**
     * Handle a hardware order that still needs warehouse serial allocation.
     * Never falls through to generic support round-robin.
     */
    public function routeHardwareSerialAllocationCase(
        Incident $incident,
        User $actor,
        string $trigger,
    ): Incident {
        $incident = $incident->fresh(['order', 'assignee']);

        $routed = $this->assignmentService->tryAssignViaHardwareOrderRouting($incident, $actor);

        if ($routed !== null && $routed->assigned_to_user_id !== null) {
            return $routed;
        }

        $order = $incident->order;

        if ($order !== null && ! $this->hasLinkedFulfilment($order)) {
            return $this->deferUntilFulfilmentIngest($incident, $actor, $trigger);
        }

        return $this->recordUnresolved($incident, $actor, $trigger);
    }

    public function retryAfterFulfilmentIngest(HardwareFulfilment $fulfilment): void
    {
        $fulfilment->loadMissing('commerceOrder');

        $supportOrderId = $fulfilment->support_order_id;

        if ($supportOrderId === null) {
            return;
        }

        $incident = Incident::query()
            ->where('order_id', $supportOrderId)
            ->where('status', '!=', 'closed')
            ->orderBy('id')
            ->first();

        if ($incident === null) {
            return;
        }

        if (! $this->shouldRetryAfterIngest($incident)) {
            return;
        }

        $actor = $incident->creator;

        if ($actor === null) {
            return;
        }

        $this->assignmentService->tryAssignViaHardwareOrderRouting(
            $incident->fresh(['order', 'assignee']),
            $actor,
        );
    }

    public function assigneeCanOperateHardwareFulfilment(?User $user): bool
    {
        return $user !== null && HardwareFulfilmentAccess::allows($user);
    }

    private function shouldRetryAfterIngest(Incident $incident): bool
    {
        $incident->loadMissing(['order', 'assignee']);

        if ($incident->assignment_origin === AssignmentOrigin::Manual) {
            return false;
        }

        if (! $this->isAwaitingInternalSerialAllocation($incident->order)) {
            return false;
        }

        if ($this->assigneeCanOperateHardwareFulfilment($incident->assignee)) {
            return false;
        }

        return $this->orderRouting->matches($incident);
    }

    private function hasLinkedFulfilment(Order $order): bool
    {
        return HardwareFulfilment::query()
            ->where('support_order_id', $order->id)
            ->exists();
    }

    private function deferUntilFulfilmentIngest(Incident $incident, User $actor, string $trigger): Incident
    {
        $incident = $this->assignmentService->clearAutomationPending($incident, $actor);

        $this->auditLogService->log(
            userId: $actor->id,
            event: self::AUDIT_DEFERRED,
            auditable: $incident,
            oldValues: [
                'assigned_to_user_id' => $incident->assigned_to_user_id,
            ],
            newValues: [
                'assigned_to_user_id' => null,
                'reason' => 'awaiting_hardware_fulfilment_ingest',
                'trigger' => $trigger,
                'order_id' => $incident->order?->order_id,
            ],
        );

        Log::info('Hardware service-case routing deferred until fulfilment ingest.', [
            'incident_id' => $incident->id,
            'order_id' => $incident->order?->order_id,
            'trigger' => $trigger,
        ]);

        return $incident->fresh(['assignee', 'order']) ?? $incident;
    }

    private function recordUnresolved(Incident $incident, User $actor, string $trigger): Incident
    {
        $incident = $this->assignmentService->clearAutomationPending($incident, $actor);

        $this->auditLogService->log(
            userId: $actor->id,
            event: self::AUDIT_UNRESOLVED,
            auditable: $incident,
            oldValues: [
                'assigned_to_user_id' => $incident->assigned_to_user_id,
            ],
            newValues: [
                'assigned_to_user_id' => null,
                'reason' => 'no_eligible_hardware_fulfilment_operator',
                'trigger' => $trigger,
                'order_id' => $incident->order?->order_id,
            ],
        );

        Log::warning('Hardware service-case routing could not resolve an operator.', [
            'incident_id' => $incident->id,
            'order_id' => $incident->order?->order_id,
            'trigger' => $trigger,
        ]);

        return $incident->fresh(['assignee', 'order']) ?? $incident;
    }
}
