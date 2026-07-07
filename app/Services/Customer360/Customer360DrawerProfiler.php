<?php

namespace App\Services\Customer360;

class Customer360DrawerProfiler
{
    /** @var array<string, float> */
    private array $timings = [];

    public function measure(string $label, callable $callback): mixed
    {
        $start = microtime(true);
        $result = $callback();
        $this->timings[$label] = round((microtime(true) - $start) * 1000, 2);

        return $result;
    }

    /**
     * @return array<string, float>
     */
    public function timings(): array
    {
        return $this->timings;
    }

    public function totalMs(): float
    {
        return round(array_sum($this->timings), 2);
    }
}
