<?php

namespace App\Services\Automation;

use App\Data\CustomerWaitingLifecycleRepairSummary;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AutomationIdentityService;
use App\Services\IncidentWaitingStateService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-time / idempotent waiting lifecycle repair.
 *
 * Never sends customer notifications — backlog hygiene only.
 */
class CustomerWaitingLifecycleRepairService
{
    public const EVENT_POLICY_REPAIRED = 'service_case.customer_waiting_policy_repaired';

    public const EVENT_STALE_WAITING_CLEARED = 'service_case.customer_waiting_stale_cleared';

    public const TARGET_POLICY = 'customer_waiting_default';

    public const MISMATCHED_POLICY = 'serial_number_default';

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly AutomationIdentityService $automationIdentity,
        private readonly IncidentWaitingStateService $waitingStateService,
        private readonly CustomerWaitingLegacyCleanupService $legacyCleanupService,
    ) {}

    public function repair(bool $dryRun = true, bool $closeLegacy = false): CustomerWaitingLifecycleRepairSummary
    {
        $counts = [
            'stale_on_closed_found' => 0,
            'stale_on_closed_cleared' => 0,
            'policy_mismatch_found' => 0,
            'policy_mismatch_repaired' => 0,
            'legacy_candidates_found' => 0,
            'legacy_closed' => 0,
        ];
        $samples = [];

        $staleStates = IncidentWaitingState::query()
            ->active()
            ->whereHas('incident', fn ($query) => $query->where('status', IncidentStatus::Closed))
            ->with('incident.order')
            ->orderBy('id')
            ->get();

        $counts['stale_on_closed_found'] = $staleStates->count();

        $actor = null;

        foreach ($staleStates as $waitingState) {
            if (count($samples) < 20) {
                $samples[] = [
                    'action' => 'clear_stale_on_closed',
                    'waiting_state_id' => $waitingState->id,
                    'incident_id' => $waitingState->incident_id,
                    'order_id' => $waitingState->incident?->order?->order_id,
                ];
            }

            if ($dryRun) {
                continue;
            }

            $incident = $waitingState->incident;

            if ($incident === null) {
                continue;
            }

            $actor = $this->resolveAutomationActor($actor);
            if ($actor === null) {
                return $this->configurationErrorSummary($dryRun, $closeLegacy, $counts, $samples);
            }

            DB::transaction(function () use ($incident, $waitingState, $actor): void {
                $this->waitingStateService->clearActiveIfPresent($incident, $actor);

                $this->auditLogService->log(
                    userId: $actor->id,
                    event: self::EVENT_STALE_WAITING_CLEARED,
                    auditable: $incident,
                    oldValues: [
                        'waiting_state_id' => $waitingState->id,
                        'waiting_reason' => $waitingState->waiting_reason->value,
                        'reminder_policy_key' => $waitingState->reminder_policy_key,
                    ],
                    newValues: [
                        'cleared' => true,
                    ],
                );
            });

            $counts['stale_on_closed_cleared']++;
        }

        $mismatched = IncidentWaitingState::query()
            ->active()
            ->where('reminder_policy_key', self::MISMATCHED_POLICY)
            ->whereHas('incident', fn ($query) => $query->where('status', '!=', IncidentStatus::Closed))
            ->with('incident.order')
            ->orderBy('id')
            ->get();

        $counts['policy_mismatch_found'] = $mismatched->count();

        foreach ($mismatched as $waitingState) {
            if (count($samples) < 40) {
                $samples[] = [
                    'action' => 'repair_policy',
                    'waiting_state_id' => $waitingState->id,
                    'incident_id' => $waitingState->incident_id,
                    'order_id' => $waitingState->incident?->order?->order_id,
                    'from_policy' => $waitingState->reminder_policy_key,
                    'to_policy' => self::TARGET_POLICY,
                ];
            }

            if ($dryRun) {
                continue;
            }

            $actor = $this->resolveAutomationActor($actor);
            if ($actor === null) {
                return $this->configurationErrorSummary($dryRun, $closeLegacy, $counts, $samples);
            }

            $incident = $waitingState->incident;
            $oldPolicy = $waitingState->reminder_policy_key;

            $waitingState->update([
                'reminder_policy_key' => self::TARGET_POLICY,
                'updated_by' => $actor->id,
            ]);

            if ($incident !== null) {
                $this->auditLogService->log(
                    userId: $actor->id,
                    event: self::EVENT_POLICY_REPAIRED,
                    auditable: $incident,
                    oldValues: [
                        'waiting_state_id' => $waitingState->id,
                        'reminder_policy_key' => $oldPolicy,
                    ],
                    newValues: [
                        'reminder_policy_key' => self::TARGET_POLICY,
                    ],
                );
            }

            $counts['policy_mismatch_repaired']++;
        }

        $legacyCandidates = $this->legacyCleanupService->legacyCandidates();
        $counts['legacy_candidates_found'] = $legacyCandidates->count();

        foreach ($legacyCandidates->take(10) as $incident) {
            /** @var Incident $incident */
            if (count($samples) < 50) {
                $samples[] = [
                    'action' => 'legacy_candidate',
                    'incident_id' => $incident->id,
                    'order_id' => $incident->order?->order_id,
                ];
            }
        }

        if ($closeLegacy) {
            $legacySummary = $this->legacyCleanupService->cleanup($dryRun);
            $counts['legacy_closed'] = $dryRun
                ? $legacySummary->wouldClose
                : $legacySummary->casesClosed;
        }

        Log::info('Customer waiting lifecycle repair completed.', [
            'dry_run' => $dryRun,
            'close_legacy' => $closeLegacy,
            'counts' => $counts,
        ]);

        return new CustomerWaitingLifecycleRepairSummary(
            dryRun: $dryRun,
            counts: $counts,
            samples: $samples,
        );
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

    /**
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $samples
     */
    private function configurationErrorSummary(
        bool $dryRun,
        bool $closeLegacy,
        array $counts,
        array $samples,
    ): CustomerWaitingLifecycleRepairSummary {
        $configuredEmail = (string) config('cashfree.system_user_email');
        $message = sprintf(
            'Automation system user not found. Set CASHFREE_SYSTEM_USER_EMAIL to an existing user email (configured: %s).',
            $configuredEmail !== '' ? $configuredEmail : '(empty)',
        );

        Log::warning('Customer waiting lifecycle repair aborted: missing automation system user.', [
            'dry_run' => $dryRun,
            'close_legacy' => $closeLegacy,
            'configured_email' => $configuredEmail,
            'counts' => $counts,
        ]);

        return new CustomerWaitingLifecycleRepairSummary(
            dryRun: $dryRun,
            counts: $counts,
            samples: $samples,
            configurationError: $message,
        );
    }
}
