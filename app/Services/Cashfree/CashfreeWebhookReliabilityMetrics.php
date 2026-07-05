<?php

namespace App\Services\Cashfree;

use App\Data\CashfreeWebhookReliabilitySnapshot;
use App\Enums\OutboxEventStatus;
use App\Models\OutboxEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class CashfreeWebhookReliabilityMetrics
{
    private const CACHE_PREFIX = 'cashfree:webhook:reliability:';

    private const KEY_ORDERS_CREATED = self::CACHE_PREFIX.'orders_created';

    private const KEY_LAST_ORDER_CREATED_AT = self::CACHE_PREFIX.'last_order_created_at';

    public function recordOrderCreated(): void
    {
        $this->increment(self::KEY_ORDERS_CREATED);
        Cache::put(self::KEY_LAST_ORDER_CREATED_AT, now()->toIso8601String(), now()->addDays(30));
    }

    public function snapshot(): CashfreeWebhookReliabilitySnapshot
    {
        return new CashfreeWebhookReliabilitySnapshot(
            ordersCreated: $this->counterValue(self::KEY_ORDERS_CREATED),
            outboxPending: $this->outboxPendingCount(),
            outboxFailed: $this->outboxFailedCount(),
            outboxCompletedToday: $this->outboxCompletedTodayCount(),
            outboxRetryCount: $this->outboxRetryCount(),
            paidWithoutDeskOrderCount: app(CashfreePaymentIntegrityService::class)->paidWithoutDeskOrderCount(),
            lastOrderCreatedAt: $this->cachedTimestamp(self::KEY_LAST_ORDER_CREATED_AT),
            capturedAt: now(),
        );
    }

    public function paidWithoutDeskOrderCount(): int
    {
        return app(CashfreePaymentIntegrityService::class)->paidWithoutDeskOrderCount();
    }

    /**
     * @return array<string, int>
     */
    public function dashboardCounts(): array
    {
        return $this->snapshot()->dashboardCounts();
    }

    private function outboxPendingCount(): int
    {
        return OutboxEvent::query()
            ->where('status', OutboxEventStatus::Pending)
            ->count();
    }

    private function outboxFailedCount(): int
    {
        return OutboxEvent::query()
            ->where('status', OutboxEventStatus::Failed)
            ->count();
    }

    private function outboxCompletedTodayCount(): int
    {
        return OutboxEvent::query()
            ->where('status', OutboxEventStatus::Completed)
            ->whereDate('processed_at', today())
            ->count();
    }

    private function outboxRetryCount(): int
    {
        return (int) OutboxEvent::query()
            ->where('status', OutboxEventStatus::Completed)
            ->where('attempts', '>', 1)
            ->sum('attempts');
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
