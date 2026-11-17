<?php

namespace App\Services\HardwareFulfilment;

/**
 * Low-overhead request-phase timings for hardware rows (Server-Timing header).
 */
final class HardwareDashboardRowsTimer
{
    private readonly float $startedAt;

    /** @var array<string, float> */
    private array $phases = [];

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    public function mark(string $phase): void
    {
        $this->phases[$phase] = microtime(true);
    }

    public function durationMs(string $phase): ?float
    {
        if (! isset($this->phases[$phase])) {
            return null;
        }

        $previous = $this->startedAt;
        $ordered = array_values($this->phases);
        $keys = array_keys($this->phases);
        $index = array_search($phase, $keys, true);

        if ($index !== false && $index > 0) {
            $previous = $ordered[$index - 1];
        }

        return max(0.0, ($this->phases[$phase] - $previous) * 1000);
    }

    public function totalMs(): float
    {
        return max(0.0, (microtime(true) - $this->startedAt) * 1000);
    }

    public function toServerTimingHeader(): string
    {
        $parts = [];
        $previous = $this->startedAt;

        foreach ($this->phases as $name => $endedAt) {
            $dur = max(0.0, ($endedAt - $previous) * 1000);
            $parts[] = sprintf('%s;dur=%.1f', $name, $dur);
            $previous = $endedAt;
        }

        $parts[] = sprintf('total;dur=%.1f', $this->totalMs());

        return implode(', ', $parts);
    }

    /**
     * @return array<string, float>
     */
    public function toLogContext(): array
    {
        $out = [];

        foreach (array_keys($this->phases) as $phase) {
            $ms = $this->durationMs($phase);
            if ($ms !== null) {
                $out[$phase.'_ms'] = round($ms, 1);
            }
        }

        $out['total_ms'] = round($this->totalMs(), 1);

        return $out;
    }
}
