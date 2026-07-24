<?php

namespace App\Services\Bonvoice;

use App\Enums\BonvoiceClickToCallFailureCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BonvoiceClickToCallMetrics
{
    private const CACHE_PREFIX = 'bonvoice:click_to_call:metrics:';

    private const TTL_DAYS = 14;

    public function recordSuccess(?string $eventId = null): void
    {
        $this->increment($this->dailyKey('success'));
        $this->increment($this->dailyKey('total'));

        Log::info('[BonVoice Click-to-Call] Metric', [
            'outcome' => 'success',
            'failure_code' => null,
            'event_id' => $eventId,
            'day' => $this->dayKey(),
        ]);
    }

    public function recordFailure(
        BonvoiceClickToCallFailureCode $failureCode,
        ?string $eventId = null,
        ?string $correlationId = null,
    ): void {
        $this->increment($this->dailyKey('failure'));
        $this->increment($this->dailyKey('total'));
        $this->increment($this->dailyKey('failure:'.$failureCode->value));

        Log::warning('[BonVoice Click-to-Call] Metric', [
            'outcome' => 'failure',
            'failure_code' => $failureCode->value,
            'event_id' => $eventId,
            'correlation_id' => $correlationId ?? $eventId,
            'day' => $this->dayKey(),
        ]);
    }

    /**
     * @return array{
     *     total: int,
     *     success: int,
     *     failure: int,
     *     by_failure_code: array<string, int>
     * }
     */
    public function todaySummary(): array
    {
        $byFailureCode = [];

        foreach (BonvoiceClickToCallFailureCode::cases() as $code) {
            $count = $this->counterValue($this->dailyKey('failure:'.$code->value));

            if ($count > 0) {
                $byFailureCode[$code->value] = $count;
            }
        }

        return [
            'total' => $this->counterValue($this->dailyKey('total')),
            'success' => $this->counterValue($this->dailyKey('success')),
            'failure' => $this->counterValue($this->dailyKey('failure')),
            'by_failure_code' => $byFailureCode,
        ];
    }

    private function dayKey(): string
    {
        return now()->toDateString();
    }

    private function dailyKey(string $suffix): string
    {
        return self::CACHE_PREFIX.$this->dayKey().':'.$suffix;
    }

    private function increment(string $key): void
    {
        if (! Cache::has($key)) {
            Cache::put($key, 0, now()->addDays(self::TTL_DAYS));
        }

        Cache::increment($key);
    }

    private function counterValue(string $key): int
    {
        return (int) Cache::get($key, 0);
    }
}
