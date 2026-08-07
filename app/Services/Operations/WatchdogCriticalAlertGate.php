<?php

namespace App\Services\Operations;

use App\Data\Operations\ProductionCriticalAlert;
use Illuminate\Support\Facades\Cache;

/**
 * Fingerprint gate for watchdog → Telegram Critical Alerts.
 *
 * Problem appears → notify once.
 * Unchanged → suppress.
 * Resolved → clear fingerprint.
 * Returns → notify again.
 * Severity increases → notify again.
 */
final class WatchdogCriticalAlertGate
{
    private const CACHE_PREFIX = 'watchdog:critical-fingerprint:';

    /**
     * Clear fingerprints for alert keys that are no longer active.
     *
     * @param  list<ProductionCriticalAlert>  $activeAlerts
     */
    public function syncResolved(array $activeAlerts): void
    {
        $activeKeys = [];
        foreach ($activeAlerts as $alert) {
            $activeKeys[$alert->key] = true;
        }

        $tracked = Cache::get($this->indexKey(), []);
        if (! is_array($tracked)) {
            $tracked = [];
        }

        $remaining = [];
        $forgetKeys = [];
        foreach ($tracked as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            if (isset($activeKeys[$key])) {
                $remaining[] = $key;
                continue;
            }

            $forgetKeys[] = $this->stateKey($key);
        }

        foreach ($forgetKeys as $forgetKey) {
            Cache::forget($forgetKey);
        }

        Cache::put($this->indexKey(), array_values(array_unique($remaining)), $this->ttl());
    }

    public function shouldNotify(ProductionCriticalAlert $alert): bool
    {
        $state = Cache::get($this->stateKey($alert->key));
        $fingerprint = $alert->fingerprint();
        $severity = $alert->severity();

        if (! is_array($state)) {
            return true;
        }

        $previousFingerprint = (string) ($state['fingerprint'] ?? '');
        $previousSeverity = (int) ($state['severity'] ?? 0);

        if ($previousFingerprint !== $fingerprint) {
            return true;
        }

        if ($severity > $previousSeverity) {
            return true;
        }

        return false;
    }

    public function markNotified(ProductionCriticalAlert $alert): void
    {
        $ttl = $this->ttl();

        Cache::put($this->stateKey($alert->key), [
            'fingerprint' => $alert->fingerprint(),
            'severity' => $alert->severity(),
            'notified_at' => now()->toIso8601String(),
        ], $ttl);

        $tracked = Cache::get($this->indexKey(), []);
        if (! is_array($tracked)) {
            $tracked = [];
        }

        if (! in_array($alert->key, $tracked, true)) {
            $tracked[] = $alert->key;
            Cache::put($this->indexKey(), array_values($tracked), $ttl);
        }
    }

    private function stateKey(string $alertKey): string
    {
        return self::CACHE_PREFIX.$alertKey;
    }

    private function indexKey(): string
    {
        return self::CACHE_PREFIX.'_index';
    }

    private function ttl(): \DateTimeInterface
    {
        return now()->addDays(2);
    }
}
