<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\SerialValidationSeverity;
use App\Enums\SerialValidationStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\SerialValidation\SerialPlaceholderService;
use App\Services\SerialValidation\SerialValidationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

class ServiceCaseAssignmentEligibilityService
{
    public const AUTOMATIC_REASSIGNMENT_REASON = 'automatic_validation_success';

    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly ServiceCaseOrderAssignmentRoutingService $orderRoutingService,
        private readonly SerialValidationService $serialValidationService,
        private readonly SerialPlaceholderService $placeholderService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
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

    public function validationSeverityForOrder(Order $order): ?SerialValidationSeverity
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

    private function evaluateSingleIncident(int $incidentId, User $actor): void
    {
        DB::transaction(function () use ($incidentId, $actor): void {
            $incident = Incident::query()
                ->whereKey($incidentId)
                ->lockForUpdate()
                ->with(['order', 'assignee'])
                ->first();

            if ($incident === null || $incident->status === IncidentStatus::Closed) {
                return;
            }

            $order = $incident->order;

            if ($order === null || ! $this->passesValidationForOrder($order)) {
                return;
            }

            $assignee = $incident->assignee;

            if ($assignee !== null && (
                $this->isAdminUser($assignee)
                || $this->orderRoutingService->isDesignatedAssignee($incident, $assignee)
            )) {
                return;
            }

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

            $this->assignmentService->assignToShiftAdminAfterValidation(
                incident: $incident,
                actor: $actor,
            );
        });
    }

    private function hasModelIdentity(Order $order): bool
    {
        return filled(trim((string) $order->device_model))
            || filled(trim((string) $order->product_name));
    }

    private function radiumBoxVerificationSucceeded(Order $order): bool
    {
        $syncStatus = $this->syncStore->status($order->id);

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
