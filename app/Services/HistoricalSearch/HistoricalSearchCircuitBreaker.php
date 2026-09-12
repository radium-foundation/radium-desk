<?php

namespace App\Services\HistoricalSearch;

use Illuminate\Support\Facades\Cache;

class HistoricalSearchCircuitBreaker
{
    private const STATE_CLOSED = 'closed';

    private const STATE_OPEN = 'open';

    /**
     * @return array{status: string, open: bool}
     */
    public function snapshot(): array
    {
        if (! config('historical_search.enabled')) {
            return ['status' => 'disabled', 'open' => false];
        }

        $state = $this->readState();

        return [
            'status' => $state['open'] ? 'degraded' : 'ok',
            'open' => (bool) $state['open'],
        ];
    }

    public function isOpen(): bool
    {
        if (! config('historical_search.enabled')) {
            return true;
        }

        return (bool) $this->readState()['open'];
    }

    public function recordSuccess(): void
    {
        Cache::forget($this->cacheKey());
    }

    public function recordFailure(): void
    {
        $config = config('historical_search.circuit_breaker');
        $state = $this->readState();
        $now = time();

        if ($state['open'] && $now < $state['open_until']) {
            return;
        }

        $windowStart = $state['window_start'] ?? $now;
        $failures = (int) ($state['failures'] ?? 0);

        if ($now - $windowStart > (int) $config['window_seconds']) {
            $windowStart = $now;
            $failures = 0;
        }

        $failures++;

        $open = $failures >= (int) $config['failure_threshold'];
        $openUntil = $open ? $now + (int) $config['open_seconds'] : 0;

        Cache::put($this->cacheKey(), [
            'open' => $open,
            'open_until' => $openUntil,
            'failures' => $failures,
            'window_start' => $windowStart,
        ], now()->addSeconds(max((int) $config['open_seconds'], (int) $config['window_seconds']) + 5));
    }

    /**
     * @return array{open: bool, open_until: int, failures: int, window_start: int}
     */
    private function readState(): array
    {
        $state = Cache::get($this->cacheKey(), [
            'open' => false,
            'open_until' => 0,
            'failures' => 0,
            'window_start' => time(),
        ]);

        if ($state['open'] && time() >= (int) $state['open_until']) {
            Cache::forget($this->cacheKey());

            return [
                'open' => false,
                'open_until' => 0,
                'failures' => 0,
                'window_start' => time(),
            ];
        }

        return $state;
    }

    private function cacheKey(): string
    {
        return (string) config('historical_search.circuit_breaker.cache_key', 'historical_search.circuit_breaker');
    }
}
