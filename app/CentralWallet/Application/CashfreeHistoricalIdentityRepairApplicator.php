<?php

namespace App\CentralWallet\Application;

use App\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Apply-mode identity repair only. Never invokes refund, ledger credit, or spoke wallet code paths.
 */
final class CashfreeHistoricalIdentityRepairApplicator
{
    public const LOCK_PREFIX = 'cashfree_historical_identity_repair:email:';

    public const CORRELATION_PREFIX = 'cashfree_historical_identity_repair:';

    public function __construct(
        private readonly CashfreeCentralCustomerBinder $binder,
    ) {}

    /**
     * @return array{status: string, desk_customer_id: ?string, central_wallet_id: ?string, error: ?string}
     */
    public function applyCohort(CashfreeHistoricalIdentityRepairCohortPlan $plan, string $runId): array
    {
        if (! in_array($plan->identityClass, [
            CashfreeHistoricalIdentityRepairIdentityClass::ExactExistingCustomer,
            CashfreeHistoricalIdentityRepairIdentityClass::NewCustomerRequired,
        ], true)) {
            return [
                'status' => 'skipped',
                'desk_customer_id' => null,
                'central_wallet_id' => null,
                'error' => 'ineligible_identity_class',
            ];
        }

        $lockKey = self::LOCK_PREFIX.($plan->subjectHash ?? $plan->cohortKey);
        $lock = Cache::lock($lockKey, (int) config('central_wallet.historical_identity_repair.lock_seconds', 120));

        try {
            return $lock->block(
                (int) config('central_wallet.historical_identity_repair.lock_wait_seconds', 30),
                fn (): array => $this->applyCohortUnderLock($plan, $runId),
            );
        } catch (LockTimeoutException) {
            return [
                'status' => 'failed',
                'desk_customer_id' => null,
                'central_wallet_id' => null,
                'error' => 'lock_timeout',
            ];
        }
    }

    /**
     * @return array{status: string, desk_customer_id: ?string, central_wallet_id: ?string, error: ?string}
     */
    private function applyCohortUnderLock(CashfreeHistoricalIdentityRepairCohortPlan $plan, string $runId): array
    {
        $deskCustomerId = null;
        $centralWalletId = null;
        $lastError = null;

        DB::transaction(function () use ($plan, $runId, &$deskCustomerId, &$centralWalletId, &$lastError): void {
            foreach ($plan->orderPrimaryKeys() as $orderPk) {
                /** @var Order|null $order */
                $order = Order::query()->whereKey($orderPk)->lockForUpdate()->first();
                if ($order === null) {
                    $lastError = 'order_missing';

                    continue;
                }

                $existing = trim((string) ($order->customer_id ?? ''));
                if ($existing !== '' && Str::isUuid($existing)) {
                    $resolved = $this->binder->resolveCustomerWallet($existing);
                    if ($resolved !== null) {
                        $deskCustomerId = $resolved['customer_id'];
                        $centralWalletId = $resolved['central_wallet_id'];

                        continue;
                    }
                }

                $status = $this->binder->bindOrder(
                    order: $order,
                    cfPaymentId: $order->cashfree_payment_id,
                    correlationId: self::CORRELATION_PREFIX.$runId,
                );

                if (in_array($status, [
                    CashfreeCentralCustomerBinder::STATUS_AMBIGUOUS,
                    CashfreeCentralCustomerBinder::STATUS_INVALID_EMAIL,
                    CashfreeCentralCustomerBinder::STATUS_WALLET_OWNER_CONFLICT,
                ], true)) {
                    $lastError = $status;
                    throw new \RuntimeException('cohort_apply_aborted:'.$status);
                }

                $order->refresh();
                $deskCustomerId = trim((string) ($order->customer_id ?? ''));
                if ($deskCustomerId !== '') {
                    $resolved = $this->binder->resolveCustomerWallet($deskCustomerId);
                    $centralWalletId = $resolved['central_wallet_id'] ?? null;
                }
            }
        });

        return [
            'status' => $lastError === null ? 'applied' : 'failed',
            'desk_customer_id' => $deskCustomerId !== '' ? $deskCustomerId : null,
            'central_wallet_id' => $centralWalletId,
            'error' => $lastError,
        ];
    }
}
