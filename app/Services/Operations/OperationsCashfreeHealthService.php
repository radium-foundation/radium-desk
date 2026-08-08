<?php

namespace App\Services\Operations;

use App\Infrastructure\IntegrationHealth\Probes\CashfreeIntegrationHealthProbe;
use App\ReadModels\Integrations\CashfreeIntegrityReadModel;
use App\Services\Cashfree\CashfreeHealthService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class OperationsCashfreeHealthService
{
    public const CACHE_KEY = 'operations:cashfree-health';

    private const CACHE_TTL_SECONDS = 30;

    public function __construct(
        private readonly CashfreeIntegrationHealthProbe $probe,
        private readonly CashfreeIntegrityReadModel $integrityReadModel,
        private readonly CashfreeHealthService $cashfreeHealthService,
    ) {}

    /**
     * Cache-only read for callers that must never trigger a rebuild (e.g. IRA highlights).
     *
     * @return array<string, mixed>|null
     */
    public function cachedWidget(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (! is_array($cached)) {
            return null;
        }

        return $this->hydrateWidgetFromCache($cached);
    }

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
        $classification = $this->integrityReadModel->classifyFailedWebhooks();
        $probeSnapshot = $this->probe->probe();
        $selfTest = $this->cashfreeHealthService->status();
        $paidWithoutDeskOrder = $this->integrityReadModel->paidWithoutDeskOrderCount();
        $configHealthy = $selfTest->isHealthy();
        // Derive alert from already-loaded paid + classify counts (avoids duplicate hydrate).
        $requiresAlert = $this->integrityReadModel->requiresCashfreeHealthAlertFromCounts(
            $paidWithoutDeskOrder,
            $classification->activeFailedWebhooks,
        );
        $isHealthy = $configHealthy && ! $requiresAlert;

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
            'last_successful_webhook_at' => $selfTest->lastSuccessfulPaymentAt ?? $probeSnapshot->lastSuccessAt,
            'last_failed_webhook_at' => $selfTest->lastFailedPaymentAt ?? $probeSnapshot->lastFailureAt,
            'latest_webhook_at' => $selfTest->latestWebhookAt,
            'webhook_secret_status' => $selfTest->webhookSecretStatus,
            'webhook_secret_status_label' => $selfTest->webhookSecretStatusLabel,
            'system_user_status' => $selfTest->systemUserStatus,
            'system_user_status_label' => $selfTest->systemUserStatusLabel,
            'system_user_email' => $selfTest->configuredEmail,
            'system_user_role_label' => $selfTest->systemUserRoleLabel,
            'queue_pending' => $selfTest->queuePending,
            'queue_failed' => $selfTest->queueFailed,
            'outbox_pending' => $selfTest->outboxPending,
            'outbox_failed' => $selfTest->outboxFailed,
            'database_ready' => $selfTest->databaseReady,
            'self_test_failures' => $selfTest->failures,
            'detail' => $this->detailMessage(
                $isHealthy,
                $configHealthy,
                $paidWithoutDeskOrder,
                $classification,
                $selfTest->systemUserStatusLabel,
                $selfTest->configuredEmail,
            ),
        ];
    }

    private function detailMessage(
        bool $isHealthy,
        bool $configHealthy,
        int $paidWithoutDeskOrder,
        \App\Data\CashfreeFailedWebhookClassificationReport $classification,
        string $systemUserStatusLabel,
        string $configuredEmail,
    ): string {
        if (! $configHealthy) {
            if ($systemUserStatusLabel === 'Missing') {
                return $configuredEmail !== ''
                    ? 'System user missing or inactive ('.$configuredEmail.').'
                    : 'System user email is not configured.';
            }

            return 'Cashfree configuration requires attention.';
        }

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
            'last_failed_webhook_at' => $this->serializeDate($widget['last_failed_webhook_at'] ?? null),
            'latest_webhook_at' => $this->serializeDate($widget['latest_webhook_at'] ?? null),
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
            'last_failed_webhook_at' => $this->hydrateDate($cached['last_failed_webhook_at'] ?? null),
            'latest_webhook_at' => $this->hydrateDate($cached['latest_webhook_at'] ?? null),
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
