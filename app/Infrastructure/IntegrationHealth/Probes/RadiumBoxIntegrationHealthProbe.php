<?php

namespace App\Infrastructure\IntegrationHealth\Probes;

use App\Infrastructure\IntegrationHealth\Contracts\IntegrationHealthProbe;
use App\Infrastructure\IntegrationHealth\IntegrationHealthSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class RadiumBoxIntegrationHealthProbe implements IntegrationHealthProbe
{
    private const RESPONSE_TIMES_KEY = 'infrastructure:integration:radiumbox:response_times';

    public function key(): string
    {
        return 'radiumbox';
    }

    public function label(): string
    {
        return 'RadiumBox';
    }

    public function probe(): IntegrationHealthSnapshot
    {
        if (! config('radiumbox.enabled')) {
            return new IntegrationHealthSnapshot(
                key: $this->key(),
                label: $this->label(),
                connectionStatus: 'disabled',
                lastSuccessAt: null,
                lastFailureAt: null,
                lastSyncAt: null,
                retryCount: 0,
                averageResponseTimeMs: null,
                lastErrorMessage: 'Integration disabled via RADIUMBOX_ENABLED.',
            );
        }

        $aggregate = Cache::get('infrastructure:integration:radiumbox:aggregate', []);

        if (! is_array($aggregate)) {
            $aggregate = [];
        }

        return new IntegrationHealthSnapshot(
            key: $this->key(),
            label: $this->label(),
            connectionStatus: (string) ($aggregate['connection_status'] ?? 'unknown'),
            lastSuccessAt: isset($aggregate['last_success_at'])
                ? Carbon::parse($aggregate['last_success_at'])
                : null,
            lastFailureAt: isset($aggregate['last_failure_at'])
                ? Carbon::parse($aggregate['last_failure_at'])
                : null,
            lastSyncAt: isset($aggregate['last_sync_at'])
                ? Carbon::parse($aggregate['last_sync_at'])
                : null,
            retryCount: (int) ($aggregate['retry_count'] ?? 0),
            averageResponseTimeMs: $this->averageResponseTimeMs(),
            lastErrorMessage: isset($aggregate['last_error_message'])
                ? (string) $aggregate['last_error_message']
                : null,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function recordAttempt(
        string $result,
        float $durationMs,
        ?string $errorMessage = null,
        array $metadata = [],
    ): void {
        $aggregateKey = 'infrastructure:integration:radiumbox:aggregate';
        $aggregate = Cache::get($aggregateKey, []);

        if (! is_array($aggregate)) {
            $aggregate = [];
        }

        $now = now()->toIso8601String();
        $samples = Cache::get(self::RESPONSE_TIMES_KEY, []);

        if (! is_array($samples)) {
            $samples = [];
        }

        $samples[] = round($durationMs, 2);

        if (count($samples) > 100) {
            $samples = array_slice($samples, -100);
        }

        Cache::put(self::RESPONSE_TIMES_KEY, $samples, now()->addDays(7));

        if (in_array($result, ['synced', 'synced_with_updates'], true)) {
            $aggregate['connection_status'] = 'healthy';
            $aggregate['last_success_at'] = $now;
            $aggregate['last_sync_at'] = $now;
            self::incrementDailyStat('successes');
        } elseif ($result === 'retry_scheduled') {
            $aggregate['connection_status'] = 'degraded';
            $aggregate['retry_count'] = (int) ($aggregate['retry_count'] ?? 0) + 1;
            self::incrementDailyStat('attempts');
        } elseif ($result === 'failed') {
            $aggregate['connection_status'] = 'degraded';
            $aggregate['last_failure_at'] = $now;
            $aggregate['last_error_message'] = $errorMessage;
            self::incrementDailyStat('failures');
        }

        $syncSource = $metadata['sync_source'] ?? null;

        if ($syncSource === 'manual') {
            self::incrementDailyStat('manual_retries');
        } elseif ($syncSource === 'scheduler') {
            self::incrementDailyStat('scheduler_recoveries');
        }

        if (in_array($result, ['synced', 'synced_with_updates', 'retry_scheduled'], true)) {
            self::incrementDailyStat('attempts');
        }

        if (($metadata['lookup_result'] ?? null) === 'disabled') {
            $aggregate['connection_status'] = 'disabled';
        }

        Cache::put($aggregateKey, $aggregate, now()->addDays(30));
    }

    private function averageResponseTimeMs(): ?float
    {
        $samples = Cache::get(self::RESPONSE_TIMES_KEY, []);

        if (! is_array($samples) || $samples === []) {
            return null;
        }

        return round(array_sum($samples) / count($samples), 2);
    }

    /**
     * @return array<string, int>
     */
    public static function dailyStats(): array
    {
        $stats = Cache::get(self::dailyStatsKey(), []);

        if (! is_array($stats)) {
            return [
                'attempts' => 0,
                'successes' => 0,
                'failures' => 0,
                'manual_retries' => 0,
                'scheduler_recoveries' => 0,
            ];
        }

        return [
            'attempts' => (int) ($stats['attempts'] ?? 0),
            'successes' => (int) ($stats['successes'] ?? 0),
            'failures' => (int) ($stats['failures'] ?? 0),
            'manual_retries' => (int) ($stats['manual_retries'] ?? 0),
            'scheduler_recoveries' => (int) ($stats['scheduler_recoveries'] ?? 0),
        ];
    }

    private static function incrementDailyStat(string $key): void
    {
        $cacheKey = self::dailyStatsKey();
        $stats = Cache::get($cacheKey, []);

        if (! is_array($stats)) {
            $stats = [];
        }

        $stats[$key] = (int) ($stats[$key] ?? 0) + 1;
        Cache::put($cacheKey, $stats, now()->endOfDay());
    }

    private static function dailyStatsKey(): string
    {
        return 'infrastructure:integration:radiumbox:stats:'.now()->format('Y-m-d');
    }
}
