<?php

namespace App\Services;

use App\Enums\CommercialState;
use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\SerialValidationSeverity;
use App\Enums\SerialValidationStatus;
use App\Services\Commercial\CommercialStateResolver;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Data\Assignment\AssignmentRequest;
use App\Enums\Assignment\AssignmentTrigger;
use App\Services\Operations\SupportAppointmentSmartAssignmentService;
use App\Support\Assignment\Strategies\ReadyQueueAssignmentStrategy;
use App\Support\Assignment\Strategies\SupportQueueAssignmentStrategy;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\SerialValidation\SerialPlaceholderService;
use App\Services\SerialValidation\SerialValidationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

class ServiceCaseAssignmentEligibilityService
{
    public const AUTOMATIC_REASSIGNMENT_REASON = 'automatic_validation_success';

    /** @var array<int, bool> */
    private array $passesValidationMemo = [];

    /** @var array<int, SerialValidationSeverity|null> */
    private array $validationSeverityMemo = [];

    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly ReadyQueueAssignmentStrategy $readyQueueStrategy,
        private readonly SupportQueueAssignmentStrategy $supportQueueStrategy,
        private readonly ServiceCaseOrderAssignmentRoutingService $orderRoutingService,
        private readonly SerialValidationService $serialValidationService,
        private readonly SerialPlaceholderService $placeholderService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
        private readonly ServiceCaseStatusService $statusService,
    ) {}

    public function evaluateAssignmentEligibility(Order $order, User $actor): void
    {
        $incidentIds = Incident::query()
            ->where('order_id', $order->id)
            ->where('status', '!=', IncidentStatus::Closed)
            ->orderBy('id')
            ->pluck('id');

        foreach ($incidentIds as $incidentId) {
            $this->evaluateSingleIncident((int) $incidentId, $actor);
        }
    }

    public function passesValidationForOrder(Order $order): bool
    {
        $orderId = (int) $order->getKey();

        if ($orderId > 0 && array_key_exists($orderId, $this->passesValidationMemo)) {
            return $this->passesValidationMemo[$orderId];
        }

        $passes = $this->computePassesValidationForOrder($order);

        if ($orderId > 0) {
            $this->passesValidationMemo[$orderId] = $passes;
        }

        return $passes;
    }

    public function validationSeverityForOrder(Order $order): ?SerialValidationSeverity
    {
        $orderId = (int) $order->getKey();

        if ($orderId > 0 && array_key_exists($orderId, $this->validationSeverityMemo)) {
            return $this->validationSeverityMemo[$orderId];
        }

        $severity = $this->computeValidationSeverityForOrder($order);

        if ($orderId > 0) {
            $this->validationSeverityMemo[$orderId] = $severity;
        }

        return $severity;
    }

    private function computePassesValidationForOrder(Order $order): bool
    {
        if (! filled(trim((string) $order->serial_number))) {
            return false;
        }

        if ($this->placeholderService->isPlaceholder((string) $order->serial_number)) {
            return false;
        }

        if (! $this->hasModelIdentity($order)) {
            return false;
        }

        $validation = $this->serialValidationService->validateForOrder(
            (string) $order->serial_number,
            $order,
        );

        if ($validation->severity === SerialValidationSeverity::Fail) {
            return false;
        }

        if ($validation->status === SerialValidationStatus::Pending) {
            return false;
        }

        return $this->radiumBoxVerificationSucceeded($order);
    }

    private function computeValidationSeverityForOrder(Order $order): ?SerialValidationSeverity
    {
        if (! filled(trim((string) $order->serial_number))) {
            return null;
        }

        if ($this->placeholderService->isPlaceholder((string) $order->serial_number)) {
            return null;
        }

        if (! $this->hasModelIdentity($order)) {
            return null;
        }

        return $this->serialValidationService
            ->validateForOrder((string) $order->serial_number, $order)
            ->severity;
    }

    public function hasValidationWarning(Order $order): bool
    {
        return $this->validationSeverityForOrder($order) === SerialValidationSeverity::Warning;
    }

    public function isWaitingForCustomerSerial(Order $order): bool
    {
        if ($order->isProductOrder() || $order->isInquiryOrder()) {
            return false;
        }

        return ! filled(trim((string) $order->serial_number))
            || $this->placeholderService->isPlaceholder((string) $order->serial_number);
    }

    public function isReadyForReferenceEntry(Order $order, Incident $incident): bool
    {
        if (app(BusinessHoldService::class)->hasActiveHold($incident)) {
            return false;
        }

        if (! $incident->isActive() || ! $incident->isPendingAdmin()) {
            return false;
        }

        $commercialResolver = app(CommercialStateResolver::class);

        if ($commercialResolver->enabled()
            && $commercialResolver->forIncident($incident)->state === CommercialState::RefundCompleted) {
            return false;
        }

        if (! filled(trim((string) $order->order_id))) {
            return false;
        }

        if (Order::isHardwareOrderId($order->order_id) || $order->isInquiryOrder()) {
            return false;
        }

        if (! $this->passesValidationForOrder($order)) {
            return false;
        }

        return $this->validationSeverityForOrder($order) !== SerialValidationSeverity::Fail;
    }

    private function evaluateSingleIncident(int $incidentId, User $actor): void
    {
        DB::transaction(function () use ($incidentId, $actor): void {
            $incident = Incident::query()
                ->whereKey($incidentId)
                ->lockForUpdate()
                ->with(['order', 'assignee', 'supportAppointments'])
                ->first();

            if ($incident === null || $incident->status === IncidentStatus::Closed) {
                return;
            }

            if (app(BusinessHoldService::class)->blocksLifecycleAdvancement($incident)) {
                return;
            }

            // Support appointment assignment is independent of Ready promotion.
            // Keep appointment ownership/reminders; do not block validation success.
            if ($incident->hasActiveSupportAppointment()) {
                app(SupportAppointmentSmartAssignmentService::class)
                    ->assignForActiveSupport($incident, $actor);

                $incident = $incident->fresh(['order', 'assignee', 'supportAppointments']);

                if ($incident === null || $incident->status === IncidentStatus::Closed) {
                    return;
                }
            }

            $order = $incident->order;

            if ($order === null) {
                return;
            }

            if (! $this->passesValidationForOrder($order)) {
                $assignee = $incident->assignee;

                if ($assignee !== null
                    && $this->isAdminUser($assignee)
                    && ! $this->orderRoutingService->isDesignatedAssignee($incident, $assignee)
                ) {
                    $this->supportQueueStrategy->assign(
                        AssignmentRequest::make(
                            incident: $incident,
                            actor: $actor,
                            trigger: AssignmentTrigger::ValidationFailure,
                        ),
                    );
                }

                return;
            }

            // Cashfree (and similar) cases start as AwaitingProductDetails. Once identity
            // validation succeeds they are Ready-eligible and must become Open so Service
            // Reference work can proceed — including when a support appointment still exists.
            $incident = $this->promoteAwaitingProductDetailsToOpen($incident, $actor);

            // Appointment ownership and incident assignee stay intact. Ready Queue
            // membership is an independent overlay (see DashboardSnapshot dual membership).
            if ($incident->hasActiveSupportAppointment()) {
                return;
            }

            $assignee = $incident->assignee;

            if ($assignee !== null && (
                $this->isAdminUser($assignee)
                || $this->orderRoutingService->isDesignatedAssignee($incident, $assignee)
            )) {
                return;
            }

            // Ready may refresh queue/SLA visibility, but must not steal Support /
            // Appointment / Refund / Sales / Manual ownership (preserves + audits).
            if ($assignee !== null && $this->isAgentUser($assignee)) {
                $this->assignmentService->reassignToShiftAdminAfterValidation(
                    incident: $incident,
                    actor: $actor,
                );

                return;
            }

            if ($incident->assigned_to_user_id !== null) {
                return;
            }

            if ($incident->automation_pending_until !== null && $incident->automation_pending_until->isPast()) {
                return;
            }

            $this->readyQueueStrategy->assign(
                AssignmentRequest::make(
                    incident: $incident,
                    actor: $actor,
                    trigger: AssignmentTrigger::ValidationSuccess,
                ),
            );
        });
    }

    private function promoteAwaitingProductDetailsToOpen(Incident $incident, User $actor): Incident
    {
        if ($incident->status !== IncidentStatus::AwaitingProductDetails) {
            return $incident;
        }

        $updated = $this->statusService->updateStatus(
            incident: $incident,
            status: IncidentStatus::Open,
            actor: $actor,
        );

        return $updated->fresh(['order', 'assignee', 'supportAppointments']) ?? $updated;
    }

    private function hasModelIdentity(Order $order): bool
    {
        return $order->hasDeviceModelAssigned()
            || filled(trim((string) $order->device_model))
            || filled(trim((string) $order->product_name));
    }

    private function radiumBoxVerificationSucceeded(Order $order): bool
    {
        $syncStatus = $this->syncStore->status($order->id, $order);

        if ($syncStatus === RadiumBoxEnrichmentSyncStatus::NotSynced) {
            return true;
        }

        return $syncStatus === RadiumBoxEnrichmentSyncStatus::Synced;
    }

    private function isAdminUser(User $user): bool
    {
        return $user->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ]);
    }

    private function isAgentUser(User $user): bool
    {
        return $user->hasRole(RolePermissionSeeder::ROLE_AGENT)
            && ! $this->isAdminUser($user);
    }
}
