<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Plans email-level cohorts for historical Cashfree identity repair (no writes).
 */
final class CashfreeHistoricalIdentityRepairPlanner
{
    /** @var array<string, array<string, true>> */
    private array $customersByHash = [];

    /** @var array<string, string> */
    private array $walletByCustomer = [];

    public function __construct(
        private readonly CashfreeHistoricalIdentityRepairPopulationQuery $population,
        private readonly CustomerIdentitySubjectHasher $hasher,
        private readonly CashfreeHistoricalIdentityRepairRefundExposureClassifier $refundExposure,
    ) {}

    public function warmCredentialIndex(): void
    {
        $this->customersByHash = [];
        foreach (CentralCustomerIdentityCredential::query()
            ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
            ->whereIn('provider', [
                CashfreeCentralCustomerBinder::PROVIDER_DESK_EMAIL,
                CashfreeCentralCustomerBinder::PROVIDER_CASHFREE_ORDER_EMAIL,
            ])
            ->whereNotNull('verified_at')
            ->select(['desk_customer_id', 'subject_hash'])
            ->cursor() as $row) {
            $this->customersByHash[$row->subject_hash][(string) $row->desk_customer_id] = true;
        }

        $this->walletByCustomer = CentralCustomer::query()->pluck('central_wallet_id', 'id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * @return list<CashfreeHistoricalIdentityRepairCohortPlan>
     */
    public function buildPlans(
        Builder $ordersQuery,
        ?int $cohortLimit = null,
    ): array {
        $this->warmCredentialIndex();

        /** @var array<string, list<array<string, mixed>>> $buckets */
        $buckets = [];

        foreach ((clone $ordersQuery)->orderBy('id')->cursor() as $order) {
            $normalized = strtolower(trim((string) ($order->customer_email ?? '')));
            $subjectHash = null;
            $identityClass = CashfreeHistoricalIdentityRepairIdentityClass::InvalidOrMissingEmail;

            $customerId = trim((string) ($order->customer_id ?? ''));
            $hasUuid = $customerId !== '' && Str::isUuid($customerId);

            if ($hasUuid) {
                $identityClass = CashfreeHistoricalIdentityRepairIdentityClass::AlreadyBound;
                $bucketKey = 'bound:'.$customerId;
            } else {
                try {
                    if ($normalized === '') {
                        throw new InvalidArgumentException('missing');
                    }
                    $subjectHash = $this->hasher->hashVerifiedEmail($normalized);
                    $matchIds = array_keys($this->customersByHash[$subjectHash] ?? []);
                    $identityClass = match (count($matchIds)) {
                        0 => CashfreeHistoricalIdentityRepairIdentityClass::NewCustomerRequired,
                        1 => CashfreeHistoricalIdentityRepairIdentityClass::ExactExistingCustomer,
                        default => CashfreeHistoricalIdentityRepairIdentityClass::Ambiguous,
                    };
                    $bucketKey = 'email:'.$subjectHash;
                } catch (InvalidArgumentException) {
                    $bucketKey = 'invalid:'.$order->id;
                }
            }

            $buckets[$bucketKey] ??= [];
            $buckets[$bucketKey][] = [
                'id' => (int) $order->id,
                'order_id' => (string) $order->order_id,
                'customer_id' => $order->customer_id,
                'customer_email' => $order->customer_email,
                'normalized_email' => $normalized,
                'subject_hash' => $subjectHash,
                'identity_class' => $identityClass,
            ];
        }

        $plans = [];
        $cohortKeys = array_keys($buckets);
        sort($cohortKeys);

        foreach ($cohortKeys as $cohortKey) {
            if ($cohortLimit !== null && $cohortLimit > 0 && count($plans) >= $cohortLimit) {
                break;
            }

            $rows = $buckets[$cohortKey];
            $identityClass = $rows[0]['identity_class'];
            $subjectHash = $rows[0]['subject_hash'] ?? null;
            $normalizedEmail = $rows[0]['normalized_email'] ?? null;

            $targetCustomer = null;
            $targetWallet = null;
            $requiresNewCustomer = false;
            $requiresNewWallet = false;

            if ($identityClass === CashfreeHistoricalIdentityRepairIdentityClass::ExactExistingCustomer && is_string($subjectHash)) {
                $matchIds = array_keys($this->customersByHash[$subjectHash] ?? []);
                $targetCustomer = $matchIds[0] ?? null;
                $targetWallet = $targetCustomer !== null ? ($this->walletByCustomer[$targetCustomer] ?? null) : null;
                if ($targetCustomer !== null && ($targetWallet === null || $targetWallet === '')) {
                    $identityClass = CashfreeHistoricalIdentityRepairIdentityClass::Ambiguous;
                }
            } elseif ($identityClass === CashfreeHistoricalIdentityRepairIdentityClass::NewCustomerRequired) {
                $requiresNewCustomer = true;
                $requiresNewWallet = true;
            }

            $orderIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            $refundFlags = $this->refundExposure->classifyForOrders($orderIds);

            $plans[] = new CashfreeHistoricalIdentityRepairCohortPlan(
                cohortKey: $cohortKey,
                normalizedEmail: $normalizedEmail,
                subjectHash: $subjectHash,
                identityClass: $identityClass,
                orders: array_map(static function (array $row): array {
                    unset($row['identity_class']);

                    return $row;
                }, $rows),
                targetDeskCustomerId: $targetCustomer,
                targetCentralWalletId: $targetWallet,
                requiresNewCustomer: $requiresNewCustomer,
                requiresNewWallet: $requiresNewWallet,
                refundFlagsByOrderId: $refundFlags,
            );
        }

        return $plans;
    }

    /**
     * @param  list<CashfreeHistoricalIdentityRepairCohortPlan>  $plans
     * @return array<string, int|list<string>>
     */
    public function summarize(array $plans): array
    {
        $counts = [
            CashfreeHistoricalIdentityRepairIdentityClass::AlreadyBound->value => 0,
            CashfreeHistoricalIdentityRepairIdentityClass::ExactExistingCustomer->value => 0,
            CashfreeHistoricalIdentityRepairIdentityClass::NewCustomerRequired->value => 0,
            CashfreeHistoricalIdentityRepairIdentityClass::Ambiguous->value => 0,
            CashfreeHistoricalIdentityRepairIdentityClass::InvalidOrMissingEmail->value => 0,
        ];

        $refundCounts = [];
        foreach (CashfreeHistoricalIdentityRepairRefundExposure::cases() as $case) {
            $refundCounts[$case->value] = 0;
        }

        $uniqueEmails = [];
        $ordersTotal = 0;
        $existingCohorts = 0;
        $newCohorts = 0;
        $ordersToBind = 0;

        foreach ($plans as $plan) {
            $ordersTotal += $plan->orderCount();
            foreach ($plan->orders as $order) {
                $counts[$plan->identityClass->value] = ($counts[$plan->identityClass->value] ?? 0) + 1;
            }

            if ($plan->normalizedEmail !== null && $plan->normalizedEmail !== '') {
                $uniqueEmails[$plan->normalizedEmail] = true;
            }

            if ($plan->identityClass === CashfreeHistoricalIdentityRepairIdentityClass::ExactExistingCustomer) {
                $existingCohorts++;
                $ordersToBind += $plan->orderCount();
            } elseif ($plan->identityClass === CashfreeHistoricalIdentityRepairIdentityClass::NewCustomerRequired) {
                $newCohorts++;
                $ordersToBind += $plan->orderCount();
            }

            $worst = $plan->worstRefundExposure();
            $refundCounts[$worst->value] = ($refundCounts[$worst->value] ?? 0) + 1;
        }

        return [
            'population_orders' => $ordersTotal,
            'unique_normalized_emails' => count($uniqueEmails),
            'identity_class_counts' => $counts,
            'existing_customer_cohorts' => $existingCohorts,
            'new_customer_cohorts' => $newCohorts,
            'orders_to_bind' => $ordersToBind,
            'refund_exposure_cohorts' => $refundCounts,
        ];
    }
}
