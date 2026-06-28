<?php

namespace App\Services\RadiumBox;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
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
}
