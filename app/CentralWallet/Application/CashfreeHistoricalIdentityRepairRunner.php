<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Infrastructure\Persistence\CashfreeHistoricalIdentityRepairCohortAudit;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class CashfreeHistoricalIdentityRepairRunner
{
    public function __construct(
        private readonly CashfreeHistoricalIdentityRepairPopulationQuery $population,
        private readonly CashfreeHistoricalIdentityRepairPlanner $planner,
        private readonly CashfreeHistoricalIdentityRepairApplicator $applicator,
    ) {}

    /**
     * @param  list<string>|null  $businessOrderIds
     */
    public function run(
        string $mode,
        ?int $cohortLimit,
        ?array $businessOrderIds,
        ?string $normalizedEmail,
        ?string $runId = null,
    ): CashfreeHistoricalIdentityRepairRunSummary {
        if (! in_array($mode, ['dry-run', 'apply'], true)) {
            throw new \InvalidArgumentException('mode_must_be_dry_run_or_apply');
        }

        if ($mode === 'apply') {
            throw new \RuntimeException('apply_mode_disabled_in_this_build');
        }

        $runId ??= 'cw-hir-'.now()->utc()->format('Ymd\THis\Z').'-'.Str::lower(Str::random(6));

        $query = $this->population->eligibleOrdersQuery();
        $this->population->applySelectionFilters($query, null, $businessOrderIds, $normalizedEmail);

        $plans = $this->planner->buildPlans($query, $cohortLimit);
        $metrics = $this->planner->summarize($plans);

        $plannedMutations = 0;
        $errors = 0;
        $samples = [];
        $rd16854 = null;

        foreach ($plans as $plan) {
            $action = $this->actionForPlan($plan);
            $status = $mode === 'dry-run' ? 'planned' : 'pending';

            if (in_array($plan->identityClass, [
                CashfreeHistoricalIdentityRepairIdentityClass::ExactExistingCustomer,
                CashfreeHistoricalIdentityRepairIdentityClass::NewCustomerRequired,
            ], true)) {
                $plannedMutations += $plan->orderCount();
            }

            foreach ($plan->orders as $orderRow) {
                if (($orderRow['order_id'] ?? '') === 'RD16854') {
                    $rd16854 = [
                        'order_pk' => $orderRow['id'],
                        'business_order_id' => 'RD16854',
                        'identity_class' => $plan->identityClass->value,
                        'normalized_email' => $plan->normalizedEmail,
                        'target_desk_customer_id' => $plan->targetDeskCustomerId,
                        'requires_new_customer' => $plan->requiresNewCustomer,
                        'refund_exposure' => ($plan->refundFlagsByOrderId[(int) $orderRow['id']] ?? CashfreeHistoricalIdentityRepairRefundExposure::NoRefundFound)->value,
                        'planned_bind' => in_array($plan->identityClass, [
                            CashfreeHistoricalIdentityRepairIdentityClass::ExactExistingCustomer,
                            CashfreeHistoricalIdentityRepairIdentityClass::NewCustomerRequired,
                        ], true),
                    ];
                }
            }

            if (count($samples) < 15 && in_array($plan->identityClass, [
                CashfreeHistoricalIdentityRepairIdentityClass::ExactExistingCustomer,
                CashfreeHistoricalIdentityRepairIdentityClass::NewCustomerRequired,
            ], true)) {
                $samples[] = [
                    'email' => $plan->normalizedEmail,
                    'orders' => $plan->orderCount(),
                    'identity_class' => $plan->identityClass->value,
                    'target_desk_customer_id' => $plan->targetDeskCustomerId,
                    'requires_new_customer' => $plan->requiresNewCustomer,
                    'refund_exposure' => $plan->worstRefundExposure()->value,
                ];
            }

        }

        $metrics['run_id'] = $runId;
        $metrics['mode'] = $mode;
        $metrics['deploy_cutoff_utc'] = $this->population->deployCutoffUtc()->toIso8601String();
        $metrics['cohorts_planned'] = count($plans);

        return new CashfreeHistoricalIdentityRepairRunSummary(
            runId: $runId,
            mode: $mode,
            metrics: $metrics,
            plannedMutations: $plannedMutations,
            errors: $errors,
            cohortSamples: $samples,
            rd16854: $rd16854,
        );
    }

    private function actionForPlan(CashfreeHistoricalIdentityRepairCohortPlan $plan): string
    {
        return match ($plan->identityClass) {
            CashfreeHistoricalIdentityRepairIdentityClass::ExactExistingCustomer => 'bind_existing_customer',
            CashfreeHistoricalIdentityRepairIdentityClass::NewCustomerRequired => 'provision_new_customer',
            CashfreeHistoricalIdentityRepairIdentityClass::AlreadyBound => 'skip_already_bound',
            CashfreeHistoricalIdentityRepairIdentityClass::Ambiguous => 'skip_ambiguous',
            CashfreeHistoricalIdentityRepairIdentityClass::InvalidOrMissingEmail => 'skip_invalid_email',
        };
    }

    /**
     * Apply mode entry — requires explicit enablement and confirmation token outside this class.
     *
     * @param  list<string>|null  $businessOrderIds
     */
    public function runApply(
        string $confirmToken,
        ?int $cohortLimit,
        ?array $businessOrderIds,
        ?string $normalizedEmail,
        ?string $runId = null,
    ): CashfreeHistoricalIdentityRepairRunSummary {
        if (! (bool) config('central_wallet.historical_identity_repair.apply_enabled', false)) {
            throw new \RuntimeException('apply_mode_not_enabled');
        }

        if ($confirmToken !== (string) config('central_wallet.historical_identity_repair.apply_confirm_token')) {
            throw new \RuntimeException('apply_confirm_token_mismatch');
        }

        $runId ??= 'cw-hir-'.now()->utc()->format('Ymd\THis\Z').'-'.Str::lower(Str::random(6));

        $query = $this->population->eligibleOrdersQuery();
        $this->population->applySelectionFilters($query, null, $businessOrderIds, $normalizedEmail);

        $plans = $this->planner->buildPlans($query, $cohortLimit);

        $plannedMutations = 0;
        $errors = 0;
        $samples = [];
        $rd16854 = null;

        foreach ($plans as $plan) {
            if (! in_array($plan->identityClass, [
                CashfreeHistoricalIdentityRepairIdentityClass::ExactExistingCustomer,
                CashfreeHistoricalIdentityRepairIdentityClass::NewCustomerRequired,
            ], true)) {
                continue;
            }

            $result = $this->applicator->applyCohort($plan, $runId);
            if ($result['status'] !== 'applied') {
                $errors++;
            } else {
                $plannedMutations += $plan->orderCount();
            }

            if (Schema::hasTable('cashfree_historical_identity_repair_cohort_audits')) {
                CashfreeHistoricalIdentityRepairCohortAudit::query()->create([
                    'run_id' => $runId,
                    'normalized_email' => $plan->normalizedEmail,
                    'subject_hash' => $plan->subjectHash,
                    'order_ids' => $plan->orderPrimaryKeys(),
                    'previous_customer_ids' => collect($plan->orders)->mapWithKeys(fn ($r) => [(string) $r['id'] => $r['customer_id']])->all(),
                    'target_desk_customer_id' => $result['desk_customer_id'],
                    'central_wallet_id' => $result['central_wallet_id'],
                    'action' => $this->actionForPlan($plan),
                    'status' => $result['status'],
                    'refund_exposure' => $plan->worstRefundExposure()->value,
                    'error_reason' => $result['error'],
                    'planned_at' => now(),
                    'applied_at' => $result['status'] === 'applied' ? now() : null,
                ]);
            }
        }

        $metrics = $this->planner->summarize($plans);
        $metrics['run_id'] = $runId;
        $metrics['mode'] = 'apply';

        return new CashfreeHistoricalIdentityRepairRunSummary(
            runId: $runId,
            mode: 'apply',
            metrics: $metrics,
            plannedMutations: $plannedMutations,
            errors: $errors,
            cohortSamples: $samples,
            rd16854: $rd16854,
        );
    }
}
