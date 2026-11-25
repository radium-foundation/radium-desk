<?php

namespace App\Services\Operations;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\ReadModels\Operations\FailedJobWatchdogReadModel;
use Illuminate\Support\Carbon;

final class WatchdogDeadLetterClassifier
{
    public const ROW_ACTIONABLE = 'actionable';

    public const ROW_RECOVERED = 'recovered';

    public const ROW_HISTORICAL = 'historical';

    public const ROW_DUPLICATE = 'duplicate';

    public const ROW_UNRESOLVABLE_RECENT = 'unresolvable_recent';

    public function __construct(
        private readonly FailedJobWatchdogReadModel $readModel,
    ) {}

    public function actionableHours(): int
    {
        return max(1, (int) config('ira.watchdog.actionable_hours', 48));
    }

    public function staleDays(): int
    {
        return max(1, (int) config('ira.watchdog.stale_days', 30));
    }

    /**
     * @return array{
     *     legacy_total: int,
     *     actionable_count: int,
     *     recovered_count: int,
     *     historical_count: int,
     *     duplicate_count: int,
     *     unresolvable_recent_count: int,
     *     actionable_uuids: list<string>,
     *     by_job_class: array<string, int>,
     * }
     */
    public function summarize(?Carbon $at = null): array
    {
        $at ??= now();
        $rows = $this->readModel->rows();
        $actionableCutoff = $at->copy()->subHours($this->actionableHours());
        $staleCutoff = $at->copy()->subDays($this->staleDays());

        $seenActionableKeys = [];
        $actionableUuids = [];
        $recovered = 0;
        $historical = 0;
        $duplicate = 0;
        $unresolvableRecent = 0;
        $byJobClass = [];

        foreach ($rows as $row) {
            $jobClass = $row['job_class'] ?? 'unknown';
            $byJobClass[$jobClass] = ($byJobClass[$jobClass] ?? 0) + 1;

            $failedAt = $row['failed_at'];
            $isRecent = $failedAt !== null
                && $failedAt->lessThanOrEqualTo($at)
                && $failedAt->greaterThanOrEqualTo($actionableCutoff);
            $isHistorical = $failedAt === null
                || $failedAt->lessThan($staleCutoff);

            $orderId = $row['order_id'];
            $syncStatus = $this->readModel->orderSyncStatus($orderId);

            if ($syncStatus === RadiumBoxEnrichmentSyncStatus::Synced) {
                $recovered++;

                continue;
            }

            if ($isHistorical) {
                $historical++;

                continue;
            }

            if ($row['malformed_payload'] || $orderId === null) {
                if ($isRecent) {
                    $unresolvableRecent++;
                    $dedupeKey = $jobClass.'|unresolvable';
                    if (! isset($seenActionableKeys[$dedupeKey])) {
                        $seenActionableKeys[$dedupeKey] = true;
                        $actionableUuids[] = $row['uuid'];
                    } else {
                        $duplicate++;
                    }
                }

                continue;
            }

            $dedupeKey = $jobClass.'|'.$orderId;
            if (isset($seenActionableKeys[$dedupeKey])) {
                $duplicate++;

                continue;
            }

            // NOT_SYNCED and other non-synced states remain actionable when recent (conservative).
            if ($isRecent) {
                $seenActionableKeys[$dedupeKey] = true;
                $actionableUuids[] = $row['uuid'];
            } else {
                $historical++;
            }
        }

        sort($actionableUuids);

        return [
            'legacy_total' => count($rows),
            'actionable_count' => count($actionableUuids),
            'recovered_count' => $recovered,
            'historical_count' => $historical,
            'duplicate_count' => $duplicate,
            'unresolvable_recent_count' => $unresolvableRecent,
            'actionable_uuids' => $actionableUuids,
            'by_job_class' => $byJobClass,
        ];
    }
}
