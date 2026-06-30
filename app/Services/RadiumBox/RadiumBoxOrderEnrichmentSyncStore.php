<?php

namespace App\Services\RadiumBox;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Order;
use App\Services\ServiceCaseAutomationMonitorService;
use Illuminate\Support\Facades\Cache;

/**
 * Lightweight sync status tracking for RadiumBox order enrichment.
 *
 * Encapsulated behind this store so status can move to persistent storage later
 * without changing callers.
 */
class RadiumBoxOrderEnrichmentSyncStore
{
    private const CACHE_PREFIX = 'radiumbox:order-enrichment:sync:';

    private const CACHE_TTL_SECONDS = 60 * 60 * 24 * 30;

    public function __construct(
        private readonly ServiceCaseAutomationMonitorService $automationMonitor,
    ) {}

    public function status(int $orderId): ?RadiumBoxEnrichmentSyncStatus
    {
        $record = $this->read($orderId);

        if ($record === null) {
            return null;
        }

        $status = $record['status'] ?? null;

        return is_string($status)
            ? RadiumBoxEnrichmentSyncStatus::tryFrom($status)
            : null;
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
        $this->write($orderId, [
            'status' => RadiumBoxEnrichmentSyncStatus::Pending->value,
            'metadata' => $this->metadata($orderId) ?? [],
            'updated_at' => now()->toIso8601String(),
        ]);

        $this->recordAutomationEvent($orderId, 'waiting');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markSynced(int $orderId, array $metadata = []): void
    {
        $this->write($orderId, [
            'status' => RadiumBoxEnrichmentSyncStatus::Synced->value,
            'metadata' => array_merge($this->metadata($orderId) ?? [], $metadata),
            'updated_at' => now()->toIso8601String(),
        ]);

        $this->recordAutomationEvent($orderId, 'verified');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markFailed(int $orderId, ?string $errorMessage = null, array $metadata = []): void
    {
        $this->write($orderId, [
            'status' => RadiumBoxEnrichmentSyncStatus::Failed->value,
            'metadata' => array_merge($this->metadata($orderId) ?? [], $metadata, array_filter([
                'last_error' => $errorMessage,
            ])),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    public function forget(int $orderId): void
    {
        Cache::forget($this->cacheKey($orderId));
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
        $metadata = $this->metadata($orderId);

        if (! is_array($metadata)) {
            return 0;
        }

        return (int) ($metadata['attempt_count'] ?? 0);
    }

    public function recordProcessingAttempt(int $orderId): void
    {
        $record = $this->read($orderId);
        $metadata = $this->metadata($orderId) ?? [];
        $metadata['attempt_count'] = $this->attemptCount($orderId) + 1;
        $metadata['last_attempt_at'] = now()->toIso8601String();

        $this->write($orderId, [
            'status' => is_array($record) ? ($record['status'] ?? RadiumBoxEnrichmentSyncStatus::Pending->value) : RadiumBoxEnrichmentSyncStatus::Pending->value,
            'metadata' => $metadata,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function write(int $orderId, array $record): void
    {
        Cache::put($this->cacheKey($orderId), $record, self::CACHE_TTL_SECONDS);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(int $orderId): ?array
    {
        $record = Cache::get($this->cacheKey($orderId));

        return is_array($record) ? $record : null;
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
