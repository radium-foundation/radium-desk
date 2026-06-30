<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\ServiceCaseAutomationStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ServiceCaseAutomationHealthService
{
    public function __construct(
        private readonly ServiceCaseAutomationStatusService $statusService,
        private readonly ServiceCaseAssignmentEligibilityService $eligibilityService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
    ) {}

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $activeIncidents = $this->activeIncidentQuery()->with(['order', 'assignee'])->get();

        $automationPending = 0;
        $waitingOverFiveMinutes = 0;
        $waitingOverFifteenMinutes = 0;
        $unassigned = 0;
        $graceExpired = 0;
        $radiumBoxPending = 0;
        $validationFailed = 0;
        $waitingForCustomerSerial = 0;
        $assignedToAgent = 0;
        $assignedToAdmin = 0;

        foreach ($activeIncidents as $incident) {
            $status = $this->statusService->statusFor($incident);

            if ($status === ServiceCaseAutomationStatus::AutomationPending) {
                $automationPending++;
            }

            if ($status === ServiceCaseAutomationStatus::WaitingRadiumbox) {
                $radiumBoxPending++;
            }

            if ($status === ServiceCaseAutomationStatus::ValidationFailed) {
                $validationFailed++;
            }

            if ($status === ServiceCaseAutomationStatus::WaitingForCustomerSerial) {
                $waitingForCustomerSerial++;
            }

            if ($status === ServiceCaseAutomationStatus::AssignedToAgent) {
                $assignedToAgent++;
            }

            if ($status === ServiceCaseAutomationStatus::AssignedToAdmin) {
                $assignedToAdmin++;
            }

            if ($incident->assigned_to_user_id === null) {
                $unassigned++;
            }

            if ($incident->automation_pending_until !== null && $incident->automation_pending_until->isPast()) {
                $graceExpired++;
            }

            if ($this->isWaitingOverMinutes($incident, 5)) {
                $waitingOverFiveMinutes++;
            }

            if ($this->isWaitingOverMinutes($incident, 15)) {
                $waitingOverFifteenMinutes++;
            }
        }

        return [
            'automation_pending' => $automationPending,
            'waiting_over_5_min' => $waitingOverFiveMinutes,
            'waiting_over_15_min' => $waitingOverFifteenMinutes,
            'unassigned' => $unassigned,
            'grace_expired' => $graceExpired,
            'radiumbox_pending' => $radiumBoxPending,
            'validation_failed' => $validationFailed,
            'waiting_for_customer_serial' => $waitingForCustomerSerial,
            'assigned_to_agent' => $assignedToAgent,
            'assigned_to_admin' => $assignedToAdmin,
            'repair_needed' => $this->ordersNeedingRepair()->count(),
        ];
    }

    /**
     * @return Collection<int, Order>
     */
    public function ordersNeedingRepair(bool $dryRun = false): Collection
    {
        $orderIds = $this->activeIncidentQuery()
            ->with(['order', 'assignee'])
            ->get()
            ->filter(fn (Incident $incident): bool => $this->isRepairCandidate($incident))
            ->pluck('order_id')
            ->filter()
            ->unique()
            ->values();

        return Order::query()
            ->whereIn('id', $orderIds)
            ->orderBy('id')
            ->get();
    }

    public function isRepairCandidate(Incident $incident): bool
    {
        if (! $incident->isActive() || $incident->order === null) {
            return false;
        }

        if ($incident->isAutomationPending()) {
            return true;
        }

        if ($incident->automation_pending_until !== null && $incident->automation_pending_until->isPast() && $incident->assigned_to_user_id === null) {
            return true;
        }

        $syncStatus = $this->syncStore->status($incident->order->id);

        if ($syncStatus === RadiumBoxEnrichmentSyncStatus::Pending) {
            return true;
        }

        $assignee = $incident->assignee;

        if ($assignee !== null
            && $assignee->hasRole(RolePermissionSeeder::ROLE_AGENT)
            && ! $assignee->hasAnyRole([RolePermissionSeeder::ROLE_ADMIN, RolePermissionSeeder::ROLE_SUPERADMIN])
            && $this->eligibilityService->passesValidationForOrder($incident->order)) {
            return true;
        }

        return $incident->assigned_to_user_id === null
            && $this->eligibilityService->passesValidationForOrder($incident->order)
            && ($incident->automation_pending_until === null || $incident->automation_pending_until->isFuture());
    }

    /**
     * @return Builder<Incident>
     */
    private function activeIncidentQuery(): Builder
    {
        return Incident::query()
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->whereNotNull('order_id');
    }

    private function isWaitingOverMinutes(Incident $incident, int $minutes): bool
    {
        if ($incident->created_at === null) {
            return false;
        }

        if ($this->statusService->statusFor($incident) === ServiceCaseAutomationStatus::Completed) {
            return false;
        }

        if ($this->statusService->statusFor($incident) === ServiceCaseAutomationStatus::AssignedToAdmin) {
            return false;
        }

        return $incident->created_at->lte(now()->subMinutes($minutes));
    }
}
