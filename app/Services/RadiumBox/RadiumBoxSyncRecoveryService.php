<?php

namespace App\Services\RadiumBox;

use App\Data\RadiumBox\RadiumBoxBatchRecoveryResult;
use App\Data\RadiumBox\RadiumBoxSyncRecoveryResult;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RadiumBoxSyncRecoveryService
{
    public function __construct(
        private readonly RadiumBoxOrderEnrichmentService $enrichmentService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
        private readonly RadiumBoxService $radiumBoxService,
        private readonly RadiumBoxEnrichmentRetryPolicy $retryPolicy,
        private readonly RadiumBoxSyncAuditService $syncAuditService,
    ) {}

    public function recover(?int $limit = null, bool $dryRun = false): RadiumBoxSyncRecoveryResult
    {
        $limit ??= (int) config('radiumbox.recovery.schedule_limit', 50);
        $scanned = 0;
        $recovered = 0;
        $skipped = 0;
        $recoveredOrderIds = [];
        $skippedOrderIds = [];

        $this->eligibleOrdersQuery()
            ->orderBy('id')
            ->chunkById(50, function ($orders) use (
                $limit,
                $dryRun,
                &$scanned,
                &$recovered,
                &$skipped,
                &$recoveredOrderIds,
                &$skippedOrderIds,
            ): bool {
                foreach ($orders as $order) {
                    if ($recovered >= $limit) {
                        return false;
                    }

                    $scanned++;

                    if (! $this->isSafeToRecover($order)) {
                        $skipped++;
                        $skippedOrderIds[] = $order->id;

                        continue;
                    }

                    if ($dryRun) {
                        $recovered++;
                        $recoveredOrderIds[] = $order->id;

                        continue;
                    }

                    $previousStatus = $this->syncStore->status($order->id)->value;

                    $this->enrichmentService->retryOrderEnrichment($order);
                    $this->syncAuditService->recordSchedulerRecovery($order, $previousStatus);

                    Log::info('RadiumBox scheduler recovery dispatched.', [
                        'order_id' => $order->order_id,
                        'order_db_id' => $order->id,
                        'previous_sync_status' => $previousStatus,
                        'new_sync_status' => RadiumBoxEnrichmentSyncStatus::Pending->value,
                        'sync_source' => 'scheduler',
                        'attempt' => $this->syncStore->attemptCount($order->id),
                        'cashfree_verified' => $order->isCashfreeVerified(),
                    ]);

                    $recovered++;
                    $recoveredOrderIds[] = $order->id;
                }

                return $recovered < $limit;
            });

        return new RadiumBoxSyncRecoveryResult(
            scanned: $scanned,
            recovered: $recovered,
            skipped: $skipped,
            recoveredOrderIds: $recoveredOrderIds,
            skippedOrderIds: $skippedOrderIds,
        );
    }

    /**
     * @param  list<int>  $orderIds
     */
    public function recoverOrders(array $orderIds): RadiumBoxBatchRecoveryResult
    {
        $recovered = 0;
        $skipped = 0;
        $recoveredOrderIds = [];
        $skippedOrderIds = [];

        if ($orderIds === []) {
            return new RadiumBoxBatchRecoveryResult(
                requested: 0,
                recovered: 0,
                skipped: 0,
            );
        }

        $orders = Order::query()
            ->whereIn('id', $orderIds)
            ->get();

        foreach ($orders as $order) {
            if (! $this->isSafeToRecover($order)) {
                $skipped++;
                $skippedOrderIds[] = $order->id;

                continue;
            }

            $previousStatus = $this->syncStore->status($order->id)->value;
            $this->enrichmentService->retryOrderEnrichment($order);
            $this->syncAuditService->recordSchedulerRecovery($order, $previousStatus);

            Log::info('RadiumBox batch recovery dispatched.', [
                'order_id' => $order->order_id,
                'order_db_id' => $order->id,
                'previous_sync_status' => $previousStatus,
                'sync_source' => 'scheduler',
                'cashfree_verified' => $order->isCashfreeVerified(),
            ]);

            $recovered++;
            $recoveredOrderIds[] = $order->id;
        }

        return new RadiumBoxBatchRecoveryResult(
            requested: count($orderIds),
            recovered: $recovered,
            skipped: $skipped,
            recoveredOrderIds: $recoveredOrderIds,
            skippedOrderIds: $skippedOrderIds,
        );
    }

    public function isSafeToRecover(Order $order): bool
    {
        if (! filled($order->order_id)
            || ! $order->isCashfreeVerified()
            || ! $this->radiumBoxService->needsEnrichment($order)) {
            return false;
        }

        if ($this->syncStore->attemptCount($order->id) >= $this->maxRecoveryAttempts()) {
            return false;
        }

        $status = $this->syncStore->status($order->id);

        if ($status === RadiumBoxEnrichmentSyncStatus::Failed) {
            return $this->retryPolicy->isWithinAutomaticWindow($order)
                && $this->retryPolicy->hasRetryIntervalElapsed(
                    $order,
                    $this->syncStore->lastAttemptAt($order->id),
                );
        }

        if ($status === RadiumBoxEnrichmentSyncStatus::Pending) {
            return $this->isStalePending($order);
        }

        if (in_array($status, [
            RadiumBoxEnrichmentSyncStatus::Synced,
            RadiumBoxEnrichmentSyncStatus::NotSynced,
        ], true)) {
            return $this->retryPolicy->hasRetryIntervalElapsed(
                $order,
                $this->syncStore->lastAttemptAt($order->id),
            );
        }

        return false;
    }

    public function isStalePending(Order $order): bool
    {
        $thresholdMinutes = (int) config('radiumbox.recovery.stale_pending_minutes', 30);
        $referenceAt = $this->resolvePendingReferenceAt($order);

        if ($referenceAt === null) {
            return true;
        }

        return $referenceAt->diffInMinutes(now()) >= $thresholdMinutes;
    }

    /**
     * @return Builder<Order>
     */
    private function eligibleOrdersQuery(): Builder
    {
        if (! Order::supportsRadiumBoxSyncTracking()) {
            return Order::query()->whereRaw('1 = 0');
        }

        return Order::query()
            ->cashfreeVerified()
            ->missingDeviceEnrichment()
            ->whereIn('radiumbox_sync_status', [
                RadiumBoxEnrichmentSyncStatus::Failed->value,
                RadiumBoxEnrichmentSyncStatus::Pending->value,
                RadiumBoxEnrichmentSyncStatus::Synced->value,
                RadiumBoxEnrichmentSyncStatus::NotSynced->value,
            ]);
    }

    private function resolvePendingReferenceAt(Order $order): ?Carbon
    {
        $lastAttempt = $this->syncStore->lastAttemptAt($order->id);

        if (is_string($lastAttempt) && $lastAttempt !== '') {
            return Carbon::parse($lastAttempt, config('app.timezone'));
        }

        return $order->radiumbox_last_sync_at;
    }

    private function maxRecoveryAttempts(): int
    {
        return max(1, (int) config('radiumbox.recovery.max_recovery_attempts', 10));
    }
}
