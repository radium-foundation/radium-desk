<?php

namespace App\Services\Cashfree;

use App\Data\CashfreeWebhookReliabilitySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class CashfreeWebhookReliabilityMetrics
{
    private const CACHE_PREFIX = 'cashfree:webhook:reliability:';

    private const KEY_ORDERS_CREATED = self::CACHE_PREFIX.'orders_created';

    private const KEY_DEFERRED_FAILURES = self::CACHE_PREFIX.'deferred_failures';

    private const KEY_SUCCESSFUL_RETRIES = self::CACHE_PREFIX.'successful_retries';

    private const KEY_LAST_ORDER_CREATED_AT = self::CACHE_PREFIX.'last_order_created_at';

    private const KEY_LAST_DEFERRED_FAILURE_AT = self::CACHE_PREFIX.'last_deferred_failure_at';

    private const KEY_LAST_SUCCESSFUL_RETRY_AT = self::CACHE_PREFIX.'last_successful_retry_at';

    public function recordOrderCreated(): void
    {
        $this->increment(self::KEY_ORDERS_CREATED);
        Cache::put(self::KEY_LAST_ORDER_CREATED_AT, now()->toIso8601String(), now()->addDays(30));
    }

    public function recordDeferredFailure(string $operation): void
    {
        $this->increment(self::KEY_DEFERRED_FAILURES);
        Cache::put(self::KEY_LAST_DEFERRED_FAILURE_AT, now()->toIso8601String(), now()->addDays(30));

        $this->increment(self::CACHE_PREFIX.'deferred_failures:'.$operation);
    }

    public function recordSuccessfulRetry(string $operation): void
    {
        $this->increment(self::KEY_SUCCESSFUL_RETRIES);
        Cache::put(self::KEY_LAST_SUCCESSFUL_RETRY_AT, now()->toIso8601String(), now()->addDays(30));

        $this->increment(self::CACHE_PREFIX.'successful_retries:'.$operation);
    }

    public function snapshot(): CashfreeWebhookReliabilitySnapshot
    {
        return new CashfreeWebhookReliabilitySnapshot(
            ordersCreated: $this->counterValue(self::KEY_ORDERS_CREATED),
            deferredTaskFailures: $this->counterValue(self::KEY_DEFERRED_FAILURES),
            successfulRetries: $this->counterValue(self::KEY_SUCCESSFUL_RETRIES),
            lastOrderCreatedAt: $this->cachedTimestamp(self::KEY_LAST_ORDER_CREATED_AT),
            lastDeferredFailureAt: $this->cachedTimestamp(self::KEY_LAST_DEFERRED_FAILURE_AT),
            lastSuccessfulRetryAt: $this->cachedTimestamp(self::KEY_LAST_SUCCESSFUL_RETRY_AT),
            capturedAt: now(),
        );
    }

    /**
     * @return array<string, int>
     */
    public function dashboardCounts(): array
    {
        return $this->snapshot()->dashboardCounts();
    }

    private function increment(string $key): void
    {
        if (! Cache::has($key)) {
            Cache::put($key, 0, now()->addDays(30));
        }

        Cache::increment($key);
    }

    private function counterValue(string $key): int
    {
        return (int) Cache::get($key, 0);
    }

    private function cachedTimestamp(string $key): ?Carbon
    {
        $value = Cache::get($key);

        return is_string($value) && $value !== ''
            ? Carbon::parse($value)
            : null;
    }
}
