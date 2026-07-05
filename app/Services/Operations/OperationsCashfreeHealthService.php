<?php

namespace App\Services\Operations;

use App\Infrastructure\IntegrationHealth\Probes\CashfreeIntegrationHealthProbe;
use App\Services\Cashfree\CashfreePaymentIntegrityService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class OperationsCashfreeHealthService
{
    private const CACHE_KEY = 'operations:cashfree-health';

    private const CACHE_TTL_SECONDS = 30;

    public function __construct(
        private readonly CashfreeIntegrationHealthProbe $probe,
        private readonly CashfreePaymentIntegrityService $integrityService,
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
        $classification = $this->integrityService->classifyFailedWebhooks();
        $probeSnapshot = $this->probe->probe();
        $paidWithoutDeskOrder = $this->integrityService->paidWithoutDeskOrderCount();
        $isHealthy = ! $this->integrityService->requiresCashfreeHealthAlert();

        return [
            'is_healthy' => $isHealthy,
            'status_label' => $isHealthy ? 'Healthy' : 'Needs attention',
            'badge_class' => $isHealthy ? 'success' : 'danger',
            'paid_without_desk_order' => $paidWithoutDeskOrder,
            'active_failed_webhooks' => $classification->activeFailedWebhooks,
            'historical_resolved_failures' => $classification->historicalResolvedFailures,
            'invalid_event_failures' => $classification->invalidEventFailures,
            'total_failed_webhooks' => $classification->totalFailed,
            'counts_by_category' => $classification->countsByCategory,
            'oldest_failed_at' => $classification->oldestFailedAt,
            'newest_failed_at' => $classification->newestFailedAt,
            'affected_order_ids' => $classification->affectedOrderIds,
            'last_successful_webhook_at' => $probeSnapshot->lastSuccessAt,
            'detail' => $this->detailMessage($isHealthy, $paidWithoutDeskOrder, $classification),
        ];
    }

    private function detailMessage(
        bool $isHealthy,
        int $paidWithoutDeskOrder,
        \App\Data\CashfreeFailedWebhookClassificationReport $classification,
    ): string {
        if ($paidWithoutDeskOrder > 0) {
            return sprintf(
                '%d paid payment(s) missing Desk orders.',
                $paidWithoutDeskOrder,
            );
        }

        if ($classification->activeFailedWebhooks > 0) {
            return sprintf(
                '%d actionable webhook failure(s) require recovery.',
                $classification->activeFailedWebhooks,
            );
        }

        if ($classification->historicalResolvedFailures > 0) {
            return sprintf(
                'Cashfree healthy. %d historical failure(s) archived.',
                $classification->historicalResolvedFailures,
            );
        }

        return 'Payment webhooks are healthy.';
    }

    /**
     * @param  array<string, mixed>  $widget
     * @return array<string, mixed>
     */
    private function toCacheArray(array $widget): array
    {
        return [
            ...$widget,
            'oldest_failed_at' => $this->serializeDate($widget['oldest_failed_at'] ?? null),
            'newest_failed_at' => $this->serializeDate($widget['newest_failed_at'] ?? null),
            'last_successful_webhook_at' => $this->serializeDate($widget['last_successful_webhook_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $cached
     * @return array<string, mixed>
     */
    private function hydrateWidgetFromCache(array $cached): array
    {
        return [
            ...$cached,
            'oldest_failed_at' => $this->hydrateDate($cached['oldest_failed_at'] ?? null),
            'newest_failed_at' => $this->hydrateDate($cached['newest_failed_at'] ?? null),
            'last_successful_webhook_at' => $this->hydrateDate($cached['last_successful_webhook_at'] ?? null),
        ];
    }

    private function serializeDate(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }

    private function hydrateDate(mixed $value): ?Carbon
    {
        if ($value instanceof CarbonInterface) {
            return $value instanceof Carbon ? $value : Carbon::parse($value);
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value);
        }

        return null;
    }
}
