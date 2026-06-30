<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\ServiceCaseAutomationStatus;
use App\Models\Incident;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;

class ServiceCaseAutomationStatusService
{
    public function __construct(
        private readonly ServiceCaseAssignmentEligibilityService $eligibilityService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
    ) {}

    public function statusFor(Incident $incident): ServiceCaseAutomationStatus
    {
        $incident->loadMissing(['order', 'assignee']);

        if (! $incident->isActive() || $incident->status === IncidentStatus::Closed) {
            return ServiceCaseAutomationStatus::Completed;
        }

        if ($incident->order !== null && $incident->order->isTransactionLocked()) {
            return ServiceCaseAutomationStatus::Completed;
        }

        if ($incident->isAutomationPending()) {
            if ($this->isWaitingRadiumBox($incident)) {
                return ServiceCaseAutomationStatus::WaitingRadiumbox;
            }

            return ServiceCaseAutomationStatus::AutomationPending;
        }

        $assignee = $incident->assignee;

        if ($assignee !== null && $this->isAdminUser($assignee)) {
            return ServiceCaseAutomationStatus::AssignedToAdmin;
        }

        $passesValidation = $incident->order !== null
            && $this->eligibilityService->passesValidationForOrder($incident->order);

        if ($assignee !== null && $this->isAgentUser($assignee)) {
            return $passesValidation
                ? ServiceCaseAutomationStatus::AssignedToAgent
                : ServiceCaseAutomationStatus::ValidationFailed;
        }

        if ($this->isWaitingRadiumBox($incident)) {
            return ServiceCaseAutomationStatus::WaitingRadiumbox;
        }

        if (! $passesValidation) {
            return ServiceCaseAutomationStatus::ValidationFailed;
        }

        return ServiceCaseAutomationStatus::AutomationPending;
    }

    private function isWaitingRadiumBox(Incident $incident): bool
    {
        if ($incident->order === null) {
            return false;
        }

        $syncStatus = $this->syncStore->status($incident->order->id);

        return $syncStatus === RadiumBoxEnrichmentSyncStatus::Pending;
    }

    private function isAdminUser(\App\Models\User $user): bool
    {
        return $user->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ]);
    }

    private function isAgentUser(\App\Models\User $user): bool
    {
        return $user->hasRole(RolePermissionSeeder::ROLE_AGENT)
            && ! $this->isAdminUser($user);
    }
}
