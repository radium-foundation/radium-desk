<?php

namespace App\ReadModels\Operations;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Order;
use App\Services\Operations\WatchdogRadiumBoxSyncFailureClassifier;
use Illuminate\Support\Carbon;

final class RadiumBoxWatchdogSyncFailureReadModel
{
    public function __construct(
        private readonly WatchdogRadiumBoxSyncFailureClassifier $classifier,
    ) {}

    /**
     * @return array{
     *     legacy_failed_count: int,
     *     actionable_count: int,
     *     aging_count: int,
     *     debt_count: int,
     *     excluded_prefix_count: int,
     *     actionable_order_ids: list<string>,
     *     actionable_incident_identity: string,
     * }
     */
    public function summarize(?Carbon $at = null): array
    {
        $at ??= now();

        if (! Order::supportsRadiumBoxSyncTracking()) {
            return $this->emptySummary();
        }

        $rows = Order::query()
            ->where('radiumbox_sync_status', RadiumBoxEnrichmentSyncStatus::Failed)
            ->get(['id', 'order_id', 'created_at', 'radiumbox_last_sync_at']);

        $legacyFailedCount = $rows->count();
        $actionableOrderIds = [];
        $actionableInternalIds = [];
        $aging = 0;
        $debt = 0;
        $excluded = 0;

        foreach ($rows as $order) {
            $publicOrderId = (string) $order->order_id;
            $classification = $this->classifier->classifyRow(
                $publicOrderId,
                $order->radiumbox_last_sync_at,
                $order->created_at,
                $at,
            );

            if ($classification['bucket'] === WatchdogRadiumBoxSyncFailureClassifier::BUCKET_EXCLUDED_PREFIX) {
                $excluded++;
                $recencyBucket = $classification['recency_bucket'] ?? WatchdogRadiumBoxSyncFailureClassifier::BUCKET_DEBT;
                if ($recencyBucket === WatchdogRadiumBoxSyncFailureClassifier::BUCKET_AGING) {
                    $aging++;
                } elseif ($recencyBucket === WatchdogRadiumBoxSyncFailureClassifier::BUCKET_DEBT) {
                    $debt++;
                }

                continue;
            }

            $bucket = $classification['bucket'];
            if ($bucket === WatchdogRadiumBoxSyncFailureClassifier::BUCKET_ACTIONABLE) {
                if ($classification['eligible_critical']) {
                    $actionableOrderIds[] = $publicOrderId;
                    $actionableInternalIds[] = (int) $order->id;
                } else {
                    // Recent but unclassified prefix — fail-safe, count as aging/debt metric only
                    $aging++;
                }
            } elseif ($bucket === WatchdogRadiumBoxSyncFailureClassifier::BUCKET_AGING) {
                $aging++;
            } else {
                $debt++;
            }
        }

        sort($actionableInternalIds, SORT_NUMERIC);
        $actionableOrderIds = array_values(array_unique($actionableOrderIds));

        return [
            'legacy_failed_count' => $legacyFailedCount,
            'actionable_count' => count($actionableInternalIds),
            'aging_count' => $aging,
            'debt_count' => $debt,
            'excluded_prefix_count' => $excluded,
            'actionable_order_ids' => $actionableOrderIds,
            'actionable_incident_identity' => implode(',', array_map('strval', $actionableInternalIds)),
        ];
    }

    /**
     * @return array<string, int|list<string>|string>
     */
    private function emptySummary(): array
    {
        return [
            'legacy_failed_count' => 0,
            'actionable_count' => 0,
            'aging_count' => 0,
            'debt_count' => 0,
            'excluded_prefix_count' => 0,
            'actionable_order_ids' => [],
            'actionable_incident_identity' => '',
        ];
    }
}
