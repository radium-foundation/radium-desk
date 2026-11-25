<?php

namespace App\ReadModels\Operations;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FailedJobWatchdogReadModel
{
    /**
     * @return list<array{
     *     uuid: string,
     *     failed_at: ?Carbon,
     *     job_class: ?string,
     *     order_id: ?int,
     *     malformed_payload: bool,
     * }>
     */
    public function rows(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        $records = DB::table('failed_jobs')
            ->orderBy('uuid')
            ->get(['uuid', 'failed_at', 'payload']);

        $rows = [];

        foreach ($records as $record) {
            $payload = json_decode((string) ($record->payload ?? ''), true);
            $jobClass = is_array($payload)
                ? (isset($payload['displayName']) ? (string) $payload['displayName'] : null)
                : null;
            $orderId = null;
            $malformed = ! is_array($payload);

            if (is_array($payload)) {
                $command = $payload['data']['command'] ?? null;
                if (is_string($command)) {
                    if (preg_match('/(?:\\\\"|")?orderId(?:\\\\"|")?;i:(\d+)/', $command, $matches) === 1) {
                        $orderId = (int) $matches[1];
                    } elseif (preg_match('/orderId";i:(\d+)/', $command, $matches) === 1) {
                        $orderId = (int) $matches[1];
                    }
                }

                if ($jobClass === null) {
                    $malformed = true;
                }
            }

            $failedAt = $record->failed_at !== null
                ? Carbon::parse($record->failed_at)
                : null;

            $rows[] = [
                'uuid' => (string) $record->uuid,
                'failed_at' => $failedAt,
                'job_class' => $jobClass,
                'order_id' => $orderId,
                'malformed_payload' => $malformed,
            ];
        }

        return $rows;
    }

    public function orderSyncStatus(?int $orderId): ?RadiumBoxEnrichmentSyncStatus
    {
        if ($orderId === null || ! Order::supportsRadiumBoxSyncTracking()) {
            return null;
        }

        $value = Order::query()->whereKey($orderId)->value('radiumbox_sync_status');

        if ($value === null) {
            return null;
        }

        return $value instanceof RadiumBoxEnrichmentSyncStatus
            ? $value
            : RadiumBoxEnrichmentSyncStatus::tryFrom((string) $value);
    }
}
