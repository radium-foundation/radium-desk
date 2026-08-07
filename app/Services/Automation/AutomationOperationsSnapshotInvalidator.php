<?php

namespace App\Services\Automation;

use App\Enums\AutomationSnapshotSlice;
use Illuminate\Support\Facades\Cache;

/**
 * Event-driven dirty flags for automation.operations.snapshot.
 *
 * No schema changes — flags live in the existing cache store.
 * Missing marks cannot permanently corrupt the snapshot: the scheduled
 * --reconcile rebuild replaces the entire payload every 15 minutes.
 */
class AutomationOperationsSnapshotInvalidator
{
    public const DIRTY_CACHE_KEY = 'automation.operations.snapshot.dirty';

    public const DIRTY_TTL_SECONDS = 3600;

    /**
     * @param  AutomationSnapshotSlice|list<AutomationSnapshotSlice>  $slices
     */
    public function markDirty(AutomationSnapshotSlice|array $slices): void
    {
        $incoming = is_array($slices) ? $slices : [$slices];
        $state = $this->read();

        foreach ($incoming as $slice) {
            if (! $slice instanceof AutomationSnapshotSlice) {
                continue;
            }

            $state['slices'][$slice->value] = true;
        }

        if (($state['marked_at'] ?? null) === null) {
            $state['marked_at'] = now()->toIso8601String();
        }

        $state['generation'] = (int) ($state['generation'] ?? 0) + 1;

        Cache::put(self::DIRTY_CACHE_KEY, $state, self::DIRTY_TTL_SECONDS);
    }

    /**
     * Convenience for write hubs that touch case/order automation state.
     */
    public function markCaseOrOrderChanged(): void
    {
        $this->markDirty([
            AutomationSnapshotSlice::Health,
            AutomationSnapshotSlice::Validation,
            AutomationSnapshotSlice::RecentEvents,
        ]);
    }

    public function markCashfreeChanged(): void
    {
        $this->markDirty(AutomationSnapshotSlice::Cashfree);
    }

    public function markRepairChanged(): void
    {
        $this->markDirty([
            AutomationSnapshotSlice::Repair,
            AutomationSnapshotSlice::Validation,
            AutomationSnapshotSlice::Health,
            AutomationSnapshotSlice::RecentEvents,
        ]);
    }

    /**
     * @return list<AutomationSnapshotSlice>
     */
    public function dirtySlices(): array
    {
        $state = $this->read();
        $slices = [];

        foreach (array_keys($state['slices'] ?? []) as $value) {
            $slice = AutomationSnapshotSlice::tryFrom((string) $value);

            if ($slice !== null) {
                $slices[] = $slice;
            }
        }

        return $slices;
    }

    public function isDirty(): bool
    {
        return $this->dirtySlices() !== [];
    }

    public function markedAt(): ?string
    {
        $markedAt = $this->read()['marked_at'] ?? null;

        return is_string($markedAt) && $markedAt !== '' ? $markedAt : null;
    }

    public function dirtyAgeSeconds(): ?int
    {
        $markedAt = $this->markedAt();

        if ($markedAt === null) {
            return null;
        }

        return (int) now()->diffInSeconds(\Illuminate\Support\Carbon::parse($markedAt), absolute: true);
    }

    public function requiresFullRebuild(): bool
    {
        foreach ($this->dirtySlices() as $slice) {
            if (in_array($slice, AutomationSnapshotSlice::fullRebuildTriggers(), true)) {
                return true;
            }
        }

        return false;
    }

    public function clear(): void
    {
        Cache::forget(self::DIRTY_CACHE_KEY);
    }

    /**
     * @param  list<AutomationSnapshotSlice>  $slices
     */
    public function clearSlices(array $slices): void
    {
        $state = $this->read();

        foreach ($slices as $slice) {
            unset($state['slices'][$slice->value]);
        }

        if (($state['slices'] ?? []) === []) {
            Cache::forget(self::DIRTY_CACHE_KEY);

            return;
        }

        Cache::put(self::DIRTY_CACHE_KEY, $state, self::DIRTY_TTL_SECONDS);
    }

    /**
     * @return array{slices: array<string, bool>, marked_at: ?string, generation: int}
     */
    private function read(): array
    {
        $raw = Cache::get(self::DIRTY_CACHE_KEY);

        if (! is_array($raw)) {
            return [
                'slices' => [],
                'marked_at' => null,
                'generation' => 0,
            ];
        }

        return [
            'slices' => is_array($raw['slices'] ?? null) ? $raw['slices'] : [],
            'marked_at' => is_string($raw['marked_at'] ?? null) ? $raw['marked_at'] : null,
            'generation' => (int) ($raw['generation'] ?? 0),
        ];
    }
}
