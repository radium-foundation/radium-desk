<?php

namespace App\Services\RadiumBox;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Order;
use App\Services\ServiceCaseAutomationMonitorService;
use Illuminate\Support\Facades\Cache;

/**
 * Sync status tracking for RadiumBox order enrichment.
 *
 * Core status fields are persisted on orders; supplemental metadata remains in cache
 * for backward compatibility with existing enrichment consumers.
 */
class RadiumBoxOrderEnrichmentSyncStore
{
    private const CACHE_PREFIX = 'radiumbox:order-enrichment:sync:';

    private const CACHE_TTL_SECONDS = 60 * 60 * 24 * 30;

    /** @var array<int, array<string, mixed>|null> */
    private array $readMemo = [];

    public function __construct(
        private readonly ServiceCaseAutomationMonitorService $automationMonitor,
    ) {}

    public function status(int $orderId, ?Order $preloadedOrder = null): RadiumBoxEnrichmentSyncStatus
    {
        $record = $this->read($orderId, $preloadedOrder);

        if ($record === null) {
            return RadiumBoxEnrichmentSyncStatus::NotSynced;
        }

        $status = $record['status'] ?? null;

        return is_string($status)
            ? (RadiumBoxEnrichmentSyncStatus::tryFrom($status) ?? RadiumBoxEnrichmentSyncStatus::NotSynced)
            : RadiumBoxEnrichmentSyncStatus::NotSynced;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function metadata(int $orderId): ?array
    {
        $record = $this->read($orderId);

        if ($record === null) {
            return null;
        }

        $metadata = $record['metadata'] ?? null;

        return is_array($metadata) ? $metadata : null;
    }

    public function markPending(int $orderId): void
    {
        $metadata = $this->metadata($orderId) ?? [];

        $this->persist($orderId, [
            'status' => RadiumBoxEnrichmentSyncStatus::Pending->value,
            'metadata' => $metadata,
            'updated_at' => now()->toIso8601String(),
            'last_sync_error' => null,
        ]);

        $this->recordAutomationEvent($orderId, 'waiting');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markSynced(int $orderId, array $metadata = []): void
    {
        $this->persist($orderId, [
            'status' => RadiumBoxEnrichmentSyncStatus::Synced->value,
            'metadata' => array_merge($this->metadata($orderId) ?? [], $metadata),
            'updated_at' => now()->toIso8601String(),
            'last_sync_error' => null,
        ]);

        $this->recordAutomationEvent($orderId, 'verified');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markFailed(int $orderId, ?string $errorMessage = null, array $metadata = []): void
    {
        $this->persist($orderId, [
            'status' => RadiumBoxEnrichmentSyncStatus::Failed->value,
            'metadata' => array_merge($this->metadata($orderId) ?? [], $metadata, array_filter([
                'last_error' => $errorMessage,
            ])),
            'updated_at' => now()->toIso8601String(),
            'last_sync_error' => $errorMessage,
        ]);
    }

    public function forget(int $orderId): void
    {
        unset($this->readMemo[$orderId]);
        Cache::forget($this->cacheKey($orderId));

        if (! Order::supportsRadiumBoxSyncTracking()) {
            return;
        }

        Order::query()->whereKey($orderId)->update([
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::NotSynced,
            'radiumbox_last_sync_at' => null,
            'radiumbox_last_sync_error' => null,
            'radiumbox_sync_attempts' => 0,
        ]);
    }

    public function updatedAt(int $orderId): ?string
    {
        $record = $this->read($orderId);

        if ($record === null) {
            return null;
        }

        $updatedAt = $record['updated_at'] ?? null;

        return is_string($updatedAt) && $updatedAt !== '' ? $updatedAt : null;
    }

    public function lastAttemptAt(int $orderId): ?string
    {
        $metadata = $this->metadata($orderId);
        $lastAttemptAt = is_array($metadata) ? ($metadata['last_attempt_at'] ?? null) : null;

        if (is_string($lastAttemptAt) && $lastAttemptAt !== '') {
            return $lastAttemptAt;
        }

        return $this->updatedAt($orderId);
    }

    public function attemptCount(int $orderId): int
    {
        if (Order::supportsRadiumBoxSyncTracking()) {
            $attempts = Order::query()->whereKey($orderId)->value('radiumbox_sync_attempts');

            if (is_numeric($attempts)) {
                return (int) $attempts;
            }
        }

        $metadata = $this->metadata($orderId);

        if (! is_array($metadata)) {
            return 0;
        }

        return (int) ($metadata['attempt_count'] ?? 0);
    }

    public function recordProcessingAttempt(int $orderId): void
    {
        $record = $this->read($orderId);
        $nextAttempt = $this->attemptCount($orderId) + 1;
        $metadata = $this->metadata($orderId) ?? [];
        $metadata['attempt_count'] = $nextAttempt;
        $metadata['last_attempt_at'] = now()->toIso8601String();

        $this->persist($orderId, [
            'status' => is_array($record) ? ($record['status'] ?? RadiumBoxEnrichmentSyncStatus::Pending->value) : RadiumBoxEnrichmentSyncStatus::Pending->value,
            'metadata' => $metadata,
            'updated_at' => now()->toIso8601String(),
        ], syncAttempts: $nextAttempt);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function persist(int $orderId, array $record, ?int $syncAttempts = null): void
    {
        unset($this->readMemo[$orderId]);
        $this->writeCacheMetadata($orderId, $record);

        if (! Order::supportsRadiumBoxSyncTracking()) {
            return;
        }

        $status = $record['status'] ?? null;
        $syncStatus = is_string($status)
            ? RadiumBoxEnrichmentSyncStatus::tryFrom($status)
            : null;

        $updates = [
            'radiumbox_sync_status' => $syncStatus ?? RadiumBoxEnrichmentSyncStatus::NotSynced,
            'radiumbox_last_sync_at' => now(),
            'radiumbox_last_sync_error' => $record['last_sync_error'] ?? null,
        ];

        if ($syncAttempts !== null) {
            $updates['radiumbox_sync_attempts'] = $syncAttempts;
        }

        Order::query()->whereKey($orderId)->update($updates);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(int $orderId, ?Order $preloadedOrder = null): ?array
    {
        if (array_key_exists($orderId, $this->readMemo)) {
            return $this->readMemo[$orderId];
        }

        return $this->readMemo[$orderId] = $this->readUncached($orderId, $preloadedOrder);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readUncached(int $orderId, ?Order $preloadedOrder = null): ?array
    {
        $metadata = $this->readCacheMetadata($orderId) ?? [];

        if (Order::supportsRadiumBoxSyncTracking()) {
            $order = ($preloadedOrder !== null && (int) $preloadedOrder->getKey() === $orderId)
                ? $preloadedOrder
                : Order::query()
                    ->whereKey($orderId)
                    ->first([
                        'id',
                        'radiumbox_sync_status',
                        'radiumbox_last_sync_at',
                        'radiumbox_last_sync_error',
                        'radiumbox_sync_attempts',
                    ]);

            if ($order !== null) {
                $status = $order->radiumbox_sync_status ?? RadiumBoxEnrichmentSyncStatus::NotSynced;

                return [
                    'status' => $status instanceof RadiumBoxEnrichmentSyncStatus
                        ? $status->value
                        : (string) $status,
                    'metadata' => $metadata,
                    'updated_at' => $order->radiumbox_last_sync_at?->toIso8601String(),
                    'last_sync_error' => $order->radiumbox_last_sync_error,
                ];
            }
        }

        $cacheRecord = $this->readCacheRecord($orderId);

        if ($cacheRecord === null) {
            return [
                'status' => RadiumBoxEnrichmentSyncStatus::NotSynced->value,
                'metadata' => $metadata,
                'updated_at' => null,
                'last_sync_error' => null,
            ];
        }

        return [
            'status' => $cacheRecord['status'] ?? null,
            'metadata' => array_merge($metadata, is_array($cacheRecord['metadata'] ?? null) ? $cacheRecord['metadata'] : []),
            'updated_at' => $cacheRecord['updated_at'] ?? null,
            'last_sync_error' => $metadata['last_error'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function writeCacheMetadata(int $orderId, array $record): void
    {
        $metadata = $record['metadata'] ?? [];

        Cache::put($this->cacheKey($orderId), [
            'status' => $record['status'] ?? null,
            'metadata' => is_array($metadata) ? $metadata : [],
            'updated_at' => $record['updated_at'] ?? now()->toIso8601String(),
        ], self::CACHE_TTL_SECONDS);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCacheRecord(int $orderId): ?array
    {
        $record = Cache::get($this->cacheKey($orderId));

        return is_array($record) ? $record : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCacheMetadata(int $orderId): ?array
    {
        $record = $this->readCacheRecord($orderId);
        $metadata = is_array($record) ? ($record['metadata'] ?? null) : null;

        return is_array($metadata) ? $metadata : null;
    }

    private function cacheKey(int $orderId): string
    {
        return self::CACHE_PREFIX.$orderId;
    }

    private function recordAutomationEvent(int $orderId, string $type): void
    {
        $order = Order::query()->with('incidents.creator')->find($orderId);

        if ($order === null) {
            return;
        }

        $actor = $order->incidents->first()?->creator
            ?? $this->automationMonitor->resolveAutomationActor();

        if ($type === 'waiting') {
            $this->automationMonitor->recordWaitingRadiumBox($order, $actor);

            return;
        }

        $this->automationMonitor->recordRadiumBoxVerified($order, $actor);
    }
}
