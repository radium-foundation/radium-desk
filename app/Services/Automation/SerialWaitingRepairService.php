<?php

namespace App\Services\Automation;

use App\Data\SerialWaitingRepairSummary;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\AutomationIdentityService;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\OrderIdentityLifecycleService;
use App\Services\ServiceCaseAssignmentEligibilityService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

/**
 * Repair active serial_number waiting states where the order already validates.
 *
 * Reuses identity lifecycle (waiting clear + assignment eligibility). Queue
 * classification is recomputed via the existing classifier for logging only.
 */
class SerialWaitingRepairService
{
    public const SOURCE = 'serial_waiting_repair';

    public function __construct(
        private readonly AutomationIdentityService $automationIdentity,
        private readonly ServiceCaseAssignmentEligibilityService $assignmentEligibility,
        private readonly OrderIdentityLifecycleService $identityLifecycle,
        private readonly OperationsQueueClassifier $queueClassifier,
        private readonly DashboardSnapshotStore $dashboardSnapshotStore,
    ) {}

    public function repair(bool $dryRun = true): SerialWaitingRepairSummary
    {
        $scanned = 0;
        $repaired = 0;
        $skipped = 0;
        $samples = [];
        $actor = null;

        $waitingStates = IncidentWaitingState::query()
            ->active()
            ->where('waiting_reason', WaitingReason::SerialNumber)
            ->whereHas(
                'incident',
                fn ($query) => $query->whereIn('status', array_map(
                    static fn (IncidentStatus $status): string => $status->value,
                    IncidentStatus::operationallyActive(),
                )),
            )
            ->with([
                'incident.order',
                'incident.assignee.roles',
                'incident.activeWaitingState',
                'incident.supportAppointments',
            ])
            ->orderBy('id')
            ->get();

        foreach ($waitingStates as $waitingState) {
            $scanned++;

            $incident = $waitingState->incident;
            $order = $incident?->order;
            $skipReason = $this->skipReason($incident, $order, $waitingState);

            if ($skipReason !== null) {
                $skipped++;

                if (count($samples) < 40) {
                    $samples[] = [
                        'action' => 'skipped',
                        'reason' => $skipReason,
                        'waiting_state_id' => $waitingState->id,
                        'incident_id' => $incident?->id,
                        'order_id' => $order?->order_id,
                    ];
                }

                continue;
            }

            /** @var Incident $incident */
            /** @var Order $order */
            if (count($samples) < 40) {
                $samples[] = [
                    'action' => $dryRun ? 'would_repair' : 'repaired',
                    'waiting_state_id' => $waitingState->id,
                    'incident_id' => $incident->id,
                    'order_id' => $order->order_id,
                    'serial_number' => $order->serial_number,
                ];
            }

            if ($dryRun) {
                $repaired++;

                continue;
            }

            $actor = $this->resolveAutomationActor($actor);

            if ($actor === null) {
                return new SerialWaitingRepairSummary(
                    dryRun: $dryRun,
                    scanned: $scanned,
                    repaired: $repaired,
                    skipped: $skipped,
                    samples: $samples,
                    configurationError: $this->configurationErrorMessage(),
                );
            }

            $this->identityLifecycle->afterIdentityChanged(
                order: $order,
                actor: $actor,
                source: self::SOURCE,
                serialChanged: true,
            );

            $freshIncident = $incident->fresh([
                'order',
                'assignee.roles',
                'activeWaitingState',
                'supportAppointments',
            ]);

            if ($freshIncident === null || $freshIncident->activeWaitingState !== null) {
                $skipped++;

                Log::warning('Serial waiting repair did not clear waiting state.', [
                    'incident_id' => $incident->id,
                    'order_id' => $order->order_id,
                    'waiting_state_id' => $waitingState->id,
                ]);

                continue;
            }

            $queue = $this->queueClassifier->classify($freshIncident);

            Log::info('Serial waiting repair repaired incident.', [
                'incident_id' => $freshIncident->id,
                'order_id' => $order->order_id,
                'waiting_state_id' => $waitingState->id,
                'queue' => $queue->value,
                'assigned_to_user_id' => $freshIncident->assigned_to_user_id,
            ]);

            $repaired++;
        }

        if (! $dryRun && $repaired > 0) {
            $this->dashboardSnapshotStore->forget();
        }

        Log::info('Serial waiting repair completed.', [
            'dry_run' => $dryRun,
            'scanned' => $scanned,
            'repaired' => $repaired,
            'skipped' => $skipped,
        ]);

        return new SerialWaitingRepairSummary(
            dryRun: $dryRun,
            scanned: $scanned,
            repaired: $repaired,
            skipped: $skipped,
            samples: $samples,
        );
    }

    private function skipReason(
        ?Incident $incident,
        ?Order $order,
        IncidentWaitingState $waitingState,
    ): ?string {
        if (! $waitingState->isActive()) {
            return 'waiting_not_active';
        }

        if ($waitingState->waiting_reason !== WaitingReason::SerialNumber) {
            return 'waiting_reason_not_serial_number';
        }

        if ($incident === null) {
            return 'incident_missing';
        }

        if (! $incident->isActive()) {
            return 'incident_not_active';
        }

        if ($order === null) {
            return 'order_missing';
        }

        if (! filled(trim((string) $order->serial_number))) {
            return 'serial_missing';
        }

        if (! $this->assignmentEligibility->passesValidationForOrder($order)) {
            return 'validation_failed';
        }

        return null;
    }

    private function resolveAutomationActor(?User $actor): ?User
    {
        if ($actor !== null) {
            return $actor;
        }

        try {
            return $this->automationIdentity->systemUser();
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    private function configurationErrorMessage(): string
    {
        $configuredEmail = (string) config('cashfree.system_user_email');

        return sprintf(
            'Automation system user not found. Set CASHFREE_SYSTEM_USER_EMAIL to an existing user email (configured: %s).',
            $configuredEmail !== '' ? $configuredEmail : '(empty)',
        );
    }
}
