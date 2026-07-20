<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\ServiceCaseAutomationStatus;
use App\Enums\SerialValidationSeverity;
use App\Models\Incident;
use App\Models\Order;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\SerialValidation\SerialPlaceholderService;
use Database\Seeders\RolePermissionSeeder;

class ServiceCaseAutomationStatusService
{
    /** @var array<int, ServiceCaseAutomationStatus> */
    private array $statusCache = [];

    public function __construct(
        private readonly ServiceCaseAssignmentEligibilityService $eligibilityService,
        private readonly SerialPlaceholderService $placeholderService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
    ) {}

    public function statusFor(Incident $incident): ServiceCaseAutomationStatus
    {
        if (array_key_exists($incident->id, $this->statusCache)) {
            return $this->statusCache[$incident->id];
        }

        $incident->loadMissing(['order', 'assignee']);

        if (! $incident->isActive() || $incident->status === IncidentStatus::Closed) {
            return $this->statusCache[$incident->id] = ServiceCaseAutomationStatus::Completed;
        }

        if ($incident->order !== null && $incident->order->isTransactionLocked()) {
            return $this->statusCache[$incident->id] = ServiceCaseAutomationStatus::Completed;
        }

        if ($incident->isAutomationPending()) {
            if ($this->isWaitingRadiumBox($incident)) {
                return $this->statusCache[$incident->id] = ServiceCaseAutomationStatus::WaitingRadiumbox;
            }

            return $this->statusCache[$incident->id] = ServiceCaseAutomationStatus::AutomationPending;
        }

        $assignee = $incident->assignee;

        if ($assignee !== null && $this->isAdminUser($assignee)) {
            return $this->statusCache[$incident->id] = ServiceCaseAutomationStatus::AssignedToAdmin;
        }

        if ($incident->order !== null && $this->isWaitingForCustomerSerial($incident->order)) {
            return $this->statusCache[$incident->id] = ServiceCaseAutomationStatus::WaitingForCustomerSerial;
        }

        $validationSeverity = $incident->order !== null
            ? $this->eligibilityService->validationSeverityForOrder($incident->order)
            : null;

        if ($validationSeverity === SerialValidationSeverity::Fail) {
            return $this->statusCache[$incident->id] = ServiceCaseAutomationStatus::ValidationFailed;
        }

        if ($validationSeverity === SerialValidationSeverity::Warning) {
            return $this->statusCache[$incident->id] = ServiceCaseAutomationStatus::ValidationWarning;
        }

        $passesValidation = $incident->order !== null
            && $this->eligibilityService->passesValidationForOrder($incident->order);

        if ($assignee !== null && $this->isAgentUser($assignee)) {
            return $this->statusCache[$incident->id] = $passesValidation
                ? ServiceCaseAutomationStatus::AssignedToAgent
                : ServiceCaseAutomationStatus::ValidationFailed;
        }

        if ($this->isWaitingRadiumBox($incident)) {
            return $this->statusCache[$incident->id] = ServiceCaseAutomationStatus::WaitingRadiumbox;
        }

        if (! $passesValidation) {
            return $this->statusCache[$incident->id] = ServiceCaseAutomationStatus::ValidationFailed;
        }

        return $this->statusCache[$incident->id] = ServiceCaseAutomationStatus::AutomationPending;
    }

    private function isWaitingForCustomerSerial(Order $order): bool
    {
        if ($order->isProductOrder() || $order->isInquiryOrder()) {
            return false;
        }

        return ! filled(trim((string) $order->serial_number))
            || $this->placeholderService->isPlaceholder((string) $order->serial_number);
    }

    private function isWaitingRadiumBox(Incident $incident): bool
    {
        if ($incident->order === null) {
            return false;
        }

        $syncStatus = $this->syncStore->status($incident->order->id, $incident->order);

        return $syncStatus === RadiumBoxEnrichmentSyncStatus::Pending;
    }

    private function isAdminUser(\App\Models\User $user): bool
    {
        return $user->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
            RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
        ]);
    }

    private function isAgentUser(\App\Models\User $user): bool
    {
        return $user->hasAnyRole([
            RolePermissionSeeder::ROLE_AGENT,
            RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
            RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
        ])
            && ! $this->isAdminUser($user);
    }
}
