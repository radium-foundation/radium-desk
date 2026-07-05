<?php

namespace App\Services\Operations;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Infrastructure\IntegrationHealth\Probes\RadiumBoxIntegrationHealthProbe;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class OperationsRadiumBoxHealthService
{
    private const CACHE_KEY = 'operations:radiumbox-health';

    private const CACHE_TTL_SECONDS = 30;

    public function __construct(
        private readonly RadiumBoxIntegrationHealthProbe $probe,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function widget(bool $useCache = true): array
    {
        if ($useCache) {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_array($cached)) {
                return $this->hydrateWidgetFromCache($cached);
            }
        }

        $widget = $this->build();
        Cache::put(self::CACHE_KEY, $this->toCacheArray($widget), now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $widget;
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        $probeSnapshot = $this->probe->probe();
        $dailyStats = RadiumBoxIntegrationHealthProbe::dailyStats();
        $attempts = max(1, $dailyStats['attempts']);
        $successRate = round(($dailyStats['successes'] / $attempts) * 100, 1);

        $statusCounts = $this->syncStatusCounts();

        return [
            'enabled' => (bool) config('radiumbox.enabled'),
            'pending_syncs' => $statusCounts['pending'],
            'failed_syncs' => $statusCounts['failed'],
            'success_rate_24h' => $successRate,
            'average_sync_duration_ms' => $probeSnapshot->averageResponseTimeMs,
            'manual_retries_24h' => $dailyStats['manual_retries'],
            'last_successful_sync_at' => $probeSnapshot->lastSuccessAt,
            'pending_orders' => $this->orderLinks(RadiumBoxEnrichmentSyncStatus::Pending),
            'failed_orders' => $this->orderLinks(RadiumBoxEnrichmentSyncStatus::Failed),
        ];
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private function toCacheArray(array $widget): array
    {
        $lastSuccessfulSyncAt = $widget['last_successful_sync_at'] ?? null;

        return [
            ...$widget,
            'last_successful_sync_at' => $lastSuccessfulSyncAt instanceof CarbonInterface
                ? $lastSuccessfulSyncAt->toIso8601String()
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $cached
     * @return array<string, mixed>
     */
    private function hydrateWidgetFromCache(array $cached): array
    {
        $lastSuccessfulSyncAt = $cached['last_successful_sync_at'] ?? null;

        if ($lastSuccessfulSyncAt instanceof CarbonInterface) {
            $hydratedLastSuccess = $lastSuccessfulSyncAt;
        } elseif (is_string($lastSuccessfulSyncAt) && $lastSuccessfulSyncAt !== '') {
            $hydratedLastSuccess = Carbon::parse($lastSuccessfulSyncAt);
        } else {
            $hydratedLastSuccess = null;
        }

        return [
            ...$cached,
            'last_successful_sync_at' => $hydratedLastSuccess,
        ];
    }

    /**
     * @return array{pending: int, failed: int}
     */
    private function syncStatusCounts(): array
    {
        if (! Order::supportsRadiumBoxSyncTracking()) {
            return ['pending' => 0, 'failed' => 0];
        }

        $counts = Order::query()
            ->selectRaw('radiumbox_sync_status, COUNT(*) as aggregate')
            ->whereIn('radiumbox_sync_status', [
                RadiumBoxEnrichmentSyncStatus::Pending->value,
                RadiumBoxEnrichmentSyncStatus::Failed->value,
            ])
            ->groupBy('radiumbox_sync_status')
            ->pluck('aggregate', 'radiumbox_sync_status');

        return [
            'pending' => (int) ($counts[RadiumBoxEnrichmentSyncStatus::Pending->value] ?? 0),
            'failed' => (int) ($counts[RadiumBoxEnrichmentSyncStatus::Failed->value] ?? 0),
        ];
    }

    /**
     * @return list<array{order_id: string, url: string}>
     */
    private function orderLinks(RadiumBoxEnrichmentSyncStatus $status): array
    {
        if (! Order::supportsRadiumBoxSyncTracking()) {
            return [];
        }

        return Order::query()
            ->where('radiumbox_sync_status', $status)
            ->orderByDesc('radiumbox_last_sync_at')
            ->limit(5)
            ->get(['id', 'order_id'])
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'order_id' => $order->order_id,
                'url' => route('orders.show', $order),
            ])
            ->all();
    }
}
