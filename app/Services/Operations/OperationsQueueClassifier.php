<?php

namespace App\Services\Operations;

use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\ServiceCaseAutomationStatus;
use App\Enums\ServiceCaseSlaStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\ServiceCaseAutomationStatusService;

class OperationsQueueClassifier
{
    public function __construct(
        private readonly ServiceCaseAutomationStatusService $automationStatusService,
    ) {}

    public function classify(Incident $incident): OperationQueue
    {
        if ($this->isCompleted($incident)) {
            return OperationQueue::Completed;
        }

        if ($this->isHardware($incident)) {
            return OperationQueue::Hardware;
        }

        if ($this->isWaitingCustomer($incident)) {
            return OperationQueue::WaitingCustomer;
        }

        if ($this->isScheduled($incident)) {
            return OperationQueue::Scheduled;
        }

        if ($this->isAttention($incident)) {
            return OperationQueue::Attention;
        }

        if ($this->isActionRequired($incident)) {
            return OperationQueue::ActionRequired;
        }

        if ($this->isPendingReview($incident)) {
            return OperationQueue::PendingReview;
        }

        return OperationQueue::ActionRequired;
    }

    public function matchesQueue(Incident $incident, OperationQueue|string $queue, ?User $scopeUser = null): bool
    {
        $queueValue = $queue instanceof OperationQueue ? $queue->value : $queue;

        if ($queueValue === OperationQueue::MyWork->value) {
            return $this->matchesMyWork($incident, $scopeUser);
        }

        return $this->classify($incident)->value === $queueValue;
    }

    public function matchesMyWork(Incident $incident, ?User $scopeUser): bool
    {
        if ($scopeUser === null || $incident->assigned_to_user_id !== $scopeUser->id) {
            return false;
        }

        if ($this->isCompleted($incident)) {
            return false;
        }

        if ($this->hasTodaysScheduledAppointment($incident)) {
            return true;
        }

        $queue = $this->classify($incident);

        if ($queue === OperationQueue::WaitingCustomer) {
            return true;
        }

        if ($queue === OperationQueue::Hardware) {
            return true;
        }

        return in_array($queue, [
            OperationQueue::ActionRequired,
            OperationQueue::Scheduled,
            OperationQueue::Attention,
        ], true);
    }

    public function isWaitingFollowUpDue(Incident $incident): bool
    {
        if (! $this->isWaitingCustomer($incident)) {
            return false;
        }

        $waitingState = $incident->relationLoaded('activeWaitingState')
            ? $incident->activeWaitingState
            : $incident->activeWaitingState()->first();

        if ($waitingState === null || ! $waitingState->isActive()) {
            return false;
        }

        return $waitingState->next_action_at !== null
            && $waitingState->next_action_at->lessThanOrEqualTo(now());
    }

    public function isAssignedWaitingCustomer(Incident $incident, ?User $scopeUser = null): bool
    {
        if (! $this->isWaitingCustomer($incident)) {
            return false;
        }

        if ($scopeUser === null) {
            return true;
        }

        return $incident->assigned_to_user_id === $scopeUser->id;
    }

    public function hasTodaysScheduledAppointment(Incident $incident): bool
    {
        if (! $incident->isPendingAdmin()) {
            return false;
        }

        $appointments = $incident->relationLoaded('supportAppointments')
            ? $incident->supportAppointments
            : $incident->supportAppointments()->get();

        if ($appointments->isEmpty()) {
            return false;
        }

        $today = now()->startOfDay();

        return $appointments->contains(
            fn ($appointment): bool => $appointment->isScheduled()
                && $appointment->preferred_date !== null
                && $appointment->preferred_date->isSameDay($today),
        );
    }

    public function isCompleted(Incident $incident): bool
    {
        if (! $incident->isActive()) {
            return true;
        }

        return $incident->order !== null && $incident->order->isTransactionLocked();
    }

    public function isHardware(Incident $incident): bool
    {
        return Order::isHardwareOrderId($incident->order?->order_id);
    }

    public function isWaitingCustomer(Incident $incident): bool
    {
        if (! $incident->isPendingAdmin()) {
            return false;
        }

        $waitingState = $incident->relationLoaded('activeWaitingState')
            ? $incident->activeWaitingState
            : $incident->activeWaitingState()->first();

        if ($waitingState !== null && $waitingState->isActive()) {
            return true;
        }

        if ($incident->order === null) {
            return false;
        }

        if ($incident->hasActiveSupportAppointment()) {
            return false;
        }

        return $this->automationStatusService->statusFor($incident) === ServiceCaseAutomationStatus::WaitingForCustomerSerial;
    }

    public function isScheduled(Incident $incident): bool
    {
        if (! $incident->isPendingAdmin()) {
            return false;
        }

        $appointments = $incident->relationLoaded('supportAppointments')
            ? $incident->supportAppointments
            : $incident->supportAppointments()->get();

        if ($appointments->isEmpty()) {
            return false;
        }

        $today = now()->startOfDay();

        return $appointments->contains(
            fn ($appointment): bool => $appointment->isScheduled()
                && $appointment->preferred_date !== null
                && $appointment->preferred_date->greaterThanOrEqualTo($today),
        );
    }

    public function isAttention(Incident $incident): bool
    {
        if (! $incident->isPendingAdmin()) {
            return false;
        }

        if ($this->isWaitingCustomer($incident)) {
            return false;
        }

        $now = now();
        $slaStatus = $incident->slaStatus($now);

        if (in_array($slaStatus, [ServiceCaseSlaStatus::Overdue, ServiceCaseSlaStatus::Warning], true)) {
            return true;
        }

        $automationStatus = $this->automationStatusService->statusFor($incident);

        if ($incident->assigned_to_user_id === null) {
            if (in_array($automationStatus, [
                ServiceCaseAutomationStatus::ValidationFailed,
                ServiceCaseAutomationStatus::AutomationPending,
            ], true)) {
                return true;
            }

            if ($incident->high_priority) {
                return true;
            }
        }

        if ($automationStatus === ServiceCaseAutomationStatus::ValidationFailed) {
            return true;
        }

        return false;
    }

    public function isActionRequired(Incident $incident): bool
    {
        if (! $incident->isPendingAdmin()) {
            return false;
        }

        if ($this->isHardware($incident)
            || $this->isWaitingCustomer($incident)
            || $this->isScheduled($incident)
            || $this->isAttention($incident)) {
            return false;
        }

        if ($incident->assigned_to_user_id !== null) {
            return true;
        }

        if (in_array($incident->status, [
            IncidentStatus::AwaitingProductDetails,
            IncidentStatus::InProgress,
        ], true)) {
            return true;
        }

        return ! $this->isStaleBacklog($incident);
    }

    public function isPendingReview(Incident $incident): bool
    {
        if (! $incident->isPendingAdmin()) {
            return false;
        }

        if ($this->isHardware($incident)
            || $this->isWaitingCustomer($incident)
            || $this->isScheduled($incident)
            || $this->isAttention($incident)
            || $this->isActionRequired($incident)) {
            return false;
        }

        return $this->isStaleBacklog($incident);
    }

    public function isStaleBacklog(Incident $incident): bool
    {
        if ($incident->assigned_to_user_id !== null || $incident->created_at === null) {
            return false;
        }

        $thresholdHours = max(1, (int) config('operations.backlog_stale_hours', 72));

        return $incident->created_at->lte(now()->subHours($thresholdHours));
    }
}
