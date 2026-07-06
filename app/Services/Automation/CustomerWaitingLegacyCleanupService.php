<?php

namespace App\Services\Automation;

use App\Data\CustomerWaitingLegacyCleanupSummary;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseCloseExceptionReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Services\AuditLogService;
use App\Services\AutomationIdentityService;
use App\Services\IncidentWaitingStateService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\RemarkService;
use App\Services\ServiceCaseStatusService;
use Illuminate\Support\Facades\DB;

class CustomerWaitingLegacyCleanupService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly AutomationIdentityService $automationIdentity,
        private readonly IncidentWaitingStateService $waitingStateService,
        private readonly OperationsQueueClassifier $queueClassifier,
        private readonly RemarkService $remarkService,
        private readonly ServiceCaseStatusService $serviceCaseStatusService,
    ) {}

    public function cleanup(bool $dryRun = false): CustomerWaitingLegacyCleanupSummary
    {
        $candidates = $this->legacyCandidates();
        $casesClosed = 0;
        $skipped = 0;

        foreach ($candidates as $incident) {
            if (! $this->shouldClose($incident)) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                continue;
            }

            if ($this->closeLegacyCase($incident)) {
                $casesClosed++;
            } else {
                $skipped++;
            }
        }

        return new CustomerWaitingLegacyCleanupSummary(
            totalFound: $candidates->count(),
            casesClosed: $casesClosed,
            skipped: $skipped,
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, Incident>
     */
    public function legacyCandidates(): \Illuminate\Support\Collection
    {
        return Incident::query()
            ->where('status', '!=', IncidentStatus::Closed)
            ->with(['order', 'activeWaitingState'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Incident $incident): bool => $this->isLegacyCandidate($incident))
            ->values();
    }

    public function isLegacyCandidate(Incident $incident): bool
    {
        if (! $incident->isActive() || ! $this->queueClassifier->isWaitingCustomer($incident)) {
            return false;
        }

        return $this->isLegacyWaitingState($this->waitingStateService->activeFor($incident));
    }

    public function isLegacyWaitingState(?IncidentWaitingState $waitingState): bool
    {
        $deploymentAt = CustomerWaitingLifecycleService::lifecycleDeploymentAt();

        if ($waitingState === null) {
            return true;
        }

        if ($waitingState->started_at?->gte($deploymentAt) === true
            && $waitingState->customer_followup_sent_at === null) {
            return false;
        }

        return $waitingState->started_at?->lt($deploymentAt) === true
            || $waitingState->customer_followup_sent_at === null;
    }

    private function shouldClose(Incident $incident): bool
    {
        return $incident->isActive()
            && $incident->status !== IncidentStatus::Closed
            && $this->queueClassifier->isWaitingCustomer($incident)
            && $this->isLegacyWaitingState($this->waitingStateService->activeFor($incident));
    }

    private function closeLegacyCase(Incident $incident): bool
    {
        $waitingState = $this->waitingStateService->activeFor($incident);
        $actor = $this->automationIdentity->systemUser();

        try {
            DB::transaction(function () use ($incident, $waitingState, $actor): void {
                $this->remarkService->createForRemarkable(
                    remarkable: $incident,
                    actor: $actor,
                    body: CustomerWaitingLifecycleService::LEGACY_CLEANUP_REMARK,
                );

                $this->serviceCaseStatusService->updateStatus($incident, IncidentStatus::Closed, $actor);

                if ($waitingState !== null && $waitingState->isActive()) {
                    $this->waitingStateService->clear($incident, $actor);
                }

                $this->auditLogService->log(
                    userId: $actor->id,
                    event: CustomerWaitingLifecycleService::EVENT_LEGACY_CLEANUP_CLOSED,
                    auditable: $incident->fresh(),
                    oldValues: [
                        'status' => $incident->status->value,
                        'waiting_reason' => $waitingState?->waiting_reason->value,
                    ],
                    newValues: [
                        'status' => IncidentStatus::Closed->value,
                        'resolution_reason' => ServiceCaseCloseExceptionReason::CustomerNotResponding->value,
                        'resolution_reason_label' => ServiceCaseCloseExceptionReason::CustomerNotResponding->label(),
                        'customer_waiting_since' => $waitingState?->started_at?->toIso8601String(),
                        'customer_followup_sent_at' => $waitingState?->customer_followup_sent_at?->toIso8601String(),
                        'waiting_reason' => $waitingState?->waiting_reason->value,
                        'waiting_reason_label' => $waitingState?->waiting_reason->label(),
                    ],
                );
            });

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
