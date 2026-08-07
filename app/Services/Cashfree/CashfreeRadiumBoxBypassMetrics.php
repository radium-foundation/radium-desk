<?php

namespace App\Services\Cashfree;

use Illuminate\Support\Facades\Cache;

/**
 * Day-scoped counters for Cashfree-first enrichment vs RadiumBox job fallback.
 * Used for operational reporting; not a durable warehouse metric.
 */
class CashfreeRadiumBoxBypassMetrics
{
    private const CACHE_PREFIX = 'cashfree:radiumbox:bypass:';

    public function recordBypass(): void
    {
        $this->increment($this->dayKey('bypassed'));
        $this->increment($this->dayKey('paid_enrichment_decisions'));
    }

    public function recordFallbackDispatch(): void
    {
        $this->increment($this->dayKey('fallback_dispatched'));
        $this->increment($this->dayKey('paid_enrichment_decisions'));
    }

    /**
     * @return array{
     *     date: string,
     *     decisions: int,
     *     bypassed: int,
     *     fallback_dispatched: int,
     *     bypass_percentage: float|null,
     * }
     */
    public function snapshot(?string $date = null): array
    {
        $date ??= now()->toDateString();
        $decisions = $this->counterValue($this->dayKey('paid_enrichment_decisions', $date));
        $bypassed = $this->counterValue($this->dayKey('bypassed', $date));
        $fallback = $this->counterValue($this->dayKey('fallback_dispatched', $date));

        return [
            'date' => $date,
            'decisions' => $decisions,
            'bypassed' => $bypassed,
            'fallback_dispatched' => $fallback,
            'bypass_percentage' => $decisions > 0
                ? round(($bypassed / $decisions) * 100, 1)
                : null,
        ];
    }

    private function dayKey(string $suffix, ?string $date = null): string
    {
        return self::CACHE_PREFIX.($date ?? now()->toDateString()).':'.$suffix;
    }

    private function increment(string $key): void
    {
        if (! Cache::has($key)) {
            Cache::put($key, 0, now()->addDays(14));
        }

        Cache::increment($key);
    }

    private function counterValue(string $key): int
    {
        return (int) Cache::get($key, 0);
    }
}
