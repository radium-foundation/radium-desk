<?php

namespace App\Services\Operations;

use App\Data\Operations\ProductionCriticalAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Fingerprint gate for watchdog → Telegram Critical Alerts.
 *
 * Problem appears → notify once.
 * Unchanged → suppress.
 * Resolved → clear fingerprint.
 * Returns → notify again.
 * Severity increases → notify again.
 *
 * Fast path: application cache.
 * Durable path: storage file — survives `php artisan optimize:clear` / `cache:clear`.
 */
final class WatchdogCriticalAlertGate
{
    private const CACHE_PREFIX = 'watchdog:critical-fingerprint:';

    private const DURABLE_FILENAME = 'watchdog-critical-fingerprints.json';

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

        $tracked = $this->readIndex();
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

            $forgetKeys[] = $key;
        }

        foreach ($forgetKeys as $forgetKey) {
            Cache::forget($this->stateKey($forgetKey));
        }

        $remaining = array_values(array_unique($remaining));
        Cache::put($this->indexKey(), $remaining, $this->ttl());
        $this->writeDurableIndexAndDropStates($remaining, $forgetKeys);
    }

    public function shouldNotify(ProductionCriticalAlert $alert): bool
    {
        $state = $this->readState($alert->key);
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
        $payload = [
            'fingerprint' => $alert->fingerprint(),
            'severity' => $alert->severity(),
            'notified_at' => now()->toIso8601String(),
        ];

        Cache::put($this->stateKey($alert->key), $payload, $ttl);

        $tracked = $this->readIndex();
        if (! in_array($alert->key, $tracked, true)) {
            $tracked[] = $alert->key;
        }

        $tracked = array_values(array_unique($tracked));
        Cache::put($this->indexKey(), $tracked, $ttl);
        $this->writeDurableState($alert->key, $payload, $tracked);
    }

    /**
     * Test helper — clears durable fingerprint file (survives Cache::flush).
     */
    public static function clearDurableForTests(): void
    {
        $path = self::durablePath();

        if (File::exists($path)) {
            File::delete($path);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readState(string $alertKey): ?array
    {
        $state = Cache::get($this->stateKey($alertKey));
        if (is_array($state)) {
            return $state;
        }

        $durable = $this->readDurable();
        $fromFile = $durable['states'][$alertKey] ?? null;
        if (! is_array($fromFile)) {
            return null;
        }

        Cache::put($this->stateKey($alertKey), $fromFile, $this->ttl());

        return $fromFile;
    }

    /**
     * @return list<string>
     */
    private function readIndex(): array
    {
        $tracked = Cache::get($this->indexKey());
        if (is_array($tracked)) {
            $keys = [];
            foreach ($tracked as $key) {
                if (is_string($key) && $key !== '') {
                    $keys[] = $key;
                }
            }

            return array_values($keys);
        }

        $durable = $this->readDurable();
        $index = $durable['index'] ?? [];
        if (! is_array($index)) {
            return [];
        }

        $keys = [];
        foreach ($index as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        $keys = array_values($keys);
        Cache::put($this->indexKey(), $keys, $this->ttl());

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $index
     */
    private function writeDurableState(string $alertKey, array $payload, array $index): void
    {
        $durable = $this->readDurable();
        $durable['states'][$alertKey] = $payload;
        $durable['index'] = array_values(array_unique($index));
        $this->writeDurable($durable);
    }

    /**
     * @param  list<string>  $remaining
     * @param  list<string>  $forgetKeys
     */
    private function writeDurableIndexAndDropStates(array $remaining, array $forgetKeys): void
    {
        $durable = $this->readDurable();
        foreach ($forgetKeys as $key) {
            unset($durable['states'][$key]);
        }
        $durable['index'] = $remaining;
        $this->writeDurable($durable);
    }

    /**
     * @return array{index: list<string>, states: array<string, array<string, mixed>>}
     */
    private function readDurable(): array
    {
        $empty = ['index' => [], 'states' => []];
        $path = self::durablePath();

        if (! File::exists($path)) {
            return $empty;
        }

        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            return $empty;
        }

        $index = is_array($decoded['index'] ?? null) ? $decoded['index'] : [];
        $states = is_array($decoded['states'] ?? null) ? $decoded['states'] : [];

        return [
            'index' => $index,
            'states' => $states,
        ];
    }

    /**
     * @param  array{index: list<string>, states: array<string, array<string, mixed>>}  $payload
     */
    private function writeDurable(array $payload): void
    {
        $path = self::durablePath();
        $directory = dirname($path);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($path, json_encode($payload, JSON_THROW_ON_ERROR));
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

    private static function durablePath(): string
    {
        if (app()->runningUnitTests()) {
            return storage_path('framework/testing/'.self::DURABLE_FILENAME);
        }

        return storage_path('framework/platform-health/'.self::DURABLE_FILENAME);
    }
}
