<?php

namespace App\Services\Operations;

use App\Data\Operations\ProductionCriticalAlert;
use App\Enums\AutomationExecutionStatus;
use App\Enums\OperationsHealthStatus;
use App\Models\AutomationExecution;
use App\Models\BonvoiceWebhookLog;
use App\Models\CashfreeWebhookLog;
use App\Models\InteraktMessage;
use App\Models\InteraktWebhookLog;
use App\Services\Cashfree\CashfreePaymentIntegrityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class ProductionWatchdogService
{
    private const UPTIME_CACHE_PREFIX = 'watchdog:uptime:';

    private const ERROR_SPIKE_WINDOW_MINUTES = 60;

    private const ERROR_SPIKE_THRESHOLD = 10;

    public function __construct(
        private readonly CashfreePaymentIntegrityService $cashfreeIntegrityService,
        private readonly OperationsIntegrationHealthService $integrationHealthService,
        private readonly OperationsSystemHealthService $systemHealthService,
        private readonly OperationsRadiumBoxHealthService $radiumBoxHealthService,
    ) {}

    /**
     * @return list<ProductionCriticalAlert>
     */
    public function collectCriticalAlerts(): array
    {
        $alerts = [
            ...$this->cashfreeAlerts(),
            ...$this->queueAlerts(),
            ...$this->automationAlerts(),
            ...$this->bonvoiceAlerts(),
            ...$this->radiumBoxAlerts(),
            ...$this->interaktAlerts(),
            ...$this->siteHealthAlerts(),
            ...$this->errorSpikeAlerts(),
        ];

        $isHealthy = $alerts === [];
        $this->recordUptimeProbe($isHealthy);

        return $alerts;
    }

    /**
     * @return array{
     *     uptime_percent: float,
     *     total_checks: int,
     *     degraded_checks: int,
     *     downtime_incidents: int,
     * }
     */
    public function todayUptimeSummary(?Carbon $at = null): array
    {
        $at ??= now();
        $payload = Cache::get($this->uptimeCacheKey($at->toDateString()));

        if (! is_array($payload)) {
            return [
                'uptime_percent' => 100.0,
                'total_checks' => 0,
                'degraded_checks' => 0,
                'downtime_incidents' => 0,
            ];
        }

        $total = max(0, (int) ($payload['total'] ?? 0));
        $degraded = max(0, (int) ($payload['degraded'] ?? 0));
        $incidents = max(0, (int) ($payload['incidents'] ?? 0));

        if ($total === 0) {
            return [
                'uptime_percent' => 100.0,
                'total_checks' => 0,
                'degraded_checks' => 0,
                'downtime_incidents' => 0,
            ];
        }

        $healthy = max(0, $total - $degraded);

        return [
            'uptime_percent' => round(($healthy / $total) * 100, 1),
            'total_checks' => $total,
            'degraded_checks' => $degraded,
            'downtime_incidents' => $incidents,
        ];
    }

    /**
     * @return list<ProductionCriticalAlert>
     */
    private function cashfreeAlerts(): array
    {
        $alerts = [];
        $paidMissing = $this->cashfreeIntegrityService->paidWithoutDeskOrderCount();

        if ($paidMissing > 0) {
            $missingRecords = collect($this->cashfreeIntegrityService->reconcile()->missingOrders)
                ->take(5)
                ->map(fn ($record): string => (string) ($record->orderId ?? $record->cfPaymentId ?? ''))
                ->filter()
                ->values()
                ->all();

            $alerts[] = new ProductionCriticalAlert(
                key: 'cashfree:paid_missing_order',
                label: 'Cashfree',
                message: sprintf(
                    '%d paid payment(s) have no matching Desk order.',
                    $paidMissing,
                ),
                affectedCount: $paidMissing,
                orderIds: $missingRecords,
            );
        }

        $activeFailed = $this->cashfreeIntegrityService->activeFailedWebhookCount();

        if ($activeFailed > 0) {
            $alerts[] = new ProductionCriticalAlert(
                key: 'cashfree:webhook_failures',
                label: 'Cashfree',
                message: sprintf(
                    '%d actionable Cashfree webhook failure(s) require recovery.',
                    $activeFailed,
                ),
                affectedCount: $activeFailed,
            );
        }

        return $alerts;
    }

    /**
     * @return list<ProductionCriticalAlert>
     */
    private function queueAlerts(): array
    {
        foreach ($this->systemHealthService->components() as $component) {
            if (($component['key'] ?? '') !== 'queue_worker') {
                continue;
            }

            $status = OperationsHealthStatus::tryFrom((string) ($component['status'] ?? ''));

            if ($status === OperationsHealthStatus::Failed) {
                return [
                    new ProductionCriticalAlert(
                        key: 'queue:dead_letter',
                        label: 'Queue',
                        message: (string) ($component['detail'] ?? 'Queue worker has failed jobs.'),
                    ),
                ];
            }

            if ($status === OperationsHealthStatus::Warning) {
                return [
                    new ProductionCriticalAlert(
                        key: 'queue:backlog',
                        label: 'Queue',
                        message: (string) ($component['detail'] ?? 'Queue backlog requires attention.'),
                    ),
                ];
            }
        }

        return [];
    }

    /**
     * @return list<ProductionCriticalAlert>
     */
    private function automationAlerts(): array
    {
        if (! Schema::hasTable('automation_executions')) {
            return [];
        }

        $threshold = max(1, (int) config('ira.watchdog.automation_failure_threshold', 3));
        $failuresToday = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Failed)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($failuresToday < $threshold) {
            return [];
        }

        return [
            new ProductionCriticalAlert(
                key: 'automation:failures',
                label: 'Automation',
                message: sprintf(
                    '%d automation execution failure(s) recorded today.',
                    $failuresToday,
                ),
                affectedCount: $failuresToday,
            ),
        ];
    }

    /**
     * @return list<ProductionCriticalAlert>
     */
    private function bonvoiceAlerts(): array
    {
        if (! Schema::hasTable('bonvoice_webhook_logs')) {
            return [];
        }

        $recentFailures = BonvoiceWebhookLog::query()
            ->where('processing_status', BonvoiceWebhookLog::STATUS_FAILED)
            ->where('received_at', '>=', now()->subHours(24))
            ->count();

        if ($recentFailures === 0) {
            return [];
        }

        return [
            new ProductionCriticalAlert(
                key: 'bonvoice:webhook_failures',
                label: 'BonVoice',
                message: sprintf(
                    '%d BonVoice webhook failure(s) in the last 24 hours.',
                    $recentFailures,
                ),
                affectedCount: $recentFailures,
            ),
        ];
    }

    /**
     * @return list<ProductionCriticalAlert>
     */
    private function radiumBoxAlerts(): array
    {
        $widget = $this->radiumBoxHealthService->widget();
        $failedSyncs = (int) ($widget['failed_syncs'] ?? 0);
        $successRate = (float) ($widget['success_rate_24h'] ?? 100);
        $minSuccessRate = (float) config('ira.watchdog.radiumbox_min_success_rate', 80);

        if ($failedSyncs > 0) {
            $orderIds = array_map(
                fn (array $order): string => (string) ($order['order_id'] ?? ''),
                is_array($widget['failed_orders'] ?? null) ? $widget['failed_orders'] : [],
            );

            return [
                new ProductionCriticalAlert(
                    key: 'radiumbox:sync_failures',
                    label: 'RadiumBox',
                    message: sprintf(
                        '%d RadiumBox sync failure(s) require attention.',
                        $failedSyncs,
                    ),
                    affectedCount: $failedSyncs,
                    orderIds: array_values(array_filter($orderIds)),
                ),
            ];
        }

        if (
            (bool) ($widget['enabled'] ?? false)
            && $this->radiumBoxHasSyncActivity($widget)
            && $successRate < $minSuccessRate
        ) {
            return [
                new ProductionCriticalAlert(
                    key: 'radiumbox:degraded',
                    label: 'RadiumBox',
                    message: sprintf(
                        'RadiumBox API success rate degraded to %.1f%% (24h).',
                        $successRate,
                    ),
                ),
            ];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $widget
     */
    private function radiumBoxHasSyncActivity(array $widget): bool
    {
        return (int) ($widget['sync_attempts_24h'] ?? 0) > 0;
    }

    /**
     * @return list<ProductionCriticalAlert>
     */
    private function interaktAlerts(): array
    {
        foreach ($this->integrationHealthService->cards() as $card) {
            if (($card['key'] ?? '') !== 'interakt') {
                continue;
            }

            $status = OperationsHealthStatus::tryFrom((string) ($card['status'] ?? ''));

            if ($status !== OperationsHealthStatus::Warning && $status !== OperationsHealthStatus::Failed) {
                continue;
            }

            $recentFailures = 0;

            if (Schema::hasTable('interakt_messages')) {
                $recentFailures = InteraktMessage::query()
                    ->where('created_at', '>=', now()->subHours(24))
                    ->whereNotNull('channel_failure_reason')
                    ->count();
            }

            $threshold = max(1, (int) config('ira.watchdog.interakt_failure_threshold', 3));

            if ($recentFailures < $threshold && $status !== OperationsHealthStatus::Failed) {
                return [];
            }

            return [
                new ProductionCriticalAlert(
                    key: 'interakt:failures',
                    label: 'Interakt',
                    message: $recentFailures > 0
                        ? sprintf('%d WhatsApp delivery failure(s) in the last 24 hours.', $recentFailures)
                        : (string) ($card['detail'] ?? 'Interakt integration requires attention.'),
                    affectedCount: $recentFailures,
                ),
            ];
        }

        if (Schema::hasTable('interakt_webhook_logs')) {
            $webhookFailures = InteraktWebhookLog::query()
                ->where('processing_status', InteraktWebhookLog::STATUS_FAILED)
                ->where('received_at', '>=', now()->subHours(24))
                ->count();

            if ($webhookFailures > 0) {
                return [
                    new ProductionCriticalAlert(
                        key: 'interakt:webhook_failures',
                        label: 'Interakt',
                        message: sprintf(
                            '%d Interakt webhook failure(s) in the last 24 hours.',
                            $webhookFailures,
                        ),
                        affectedCount: $webhookFailures,
                    ),
                ];
            }
        }

        return [];
    }

    /**
     * @return list<ProductionCriticalAlert>
     */
    private function siteHealthAlerts(): array
    {
        $healthUrl = rtrim((string) config('app.url'), '/').'/up';

        try {
            $response = Http::timeout(5)->get($healthUrl);

            if ($response->successful()) {
                return [];
            }
        } catch (\Throwable) {
            return [
                new ProductionCriticalAlert(
                    key: 'site:down',
                    label: 'Site Health',
                    message: 'Application health endpoint is unreachable.',
                ),
            ];
        }

        return [
            new ProductionCriticalAlert(
                key: 'site:unhealthy',
                label: 'Site Health',
                message: sprintf(
                    'Application health check returned HTTP %d.',
                    $response->status(),
                ),
            ),
        ];
    }

    /**
     * @return list<ProductionCriticalAlert>
     */
    private function errorSpikeAlerts(): array
    {
        if (! Schema::hasTable('cashfree_webhook_logs')) {
            return [];
        }

        $since = now()->subMinutes(self::ERROR_SPIKE_WINDOW_MINUTES);
        $spikeCount = CashfreeWebhookLog::query()
            ->where('processing_status', CashfreeWebhookLog::STATUS_FAILED)
            ->where('processed_at', '>=', $since)
            ->count();

        $spikeCount += BonvoiceWebhookLog::query()
            ->where('processing_status', BonvoiceWebhookLog::STATUS_FAILED)
            ->where('received_at', '>=', $since)
            ->count();

        if (Schema::hasTable('interakt_webhook_logs')) {
            $spikeCount += InteraktWebhookLog::query()
                ->where('processing_status', InteraktWebhookLog::STATUS_FAILED)
                ->where('received_at', '>=', $since)
                ->count();
        }

        if ($spikeCount < self::ERROR_SPIKE_THRESHOLD) {
            return [];
        }

        return [
            new ProductionCriticalAlert(
                key: 'errors:spike',
                label: 'Error Spike',
                message: sprintf(
                    '%d webhook/integration failure(s) in the last %d minutes.',
                    $spikeCount,
                    self::ERROR_SPIKE_WINDOW_MINUTES,
                ),
                affectedCount: $spikeCount,
            ),
        ];
    }

    private function recordUptimeProbe(bool $healthy): void
    {
        $date = now()->toDateString();
        $key = $this->uptimeCacheKey($date);
        $payload = Cache::get($key);

        if (! is_array($payload)) {
            $payload = [
                'total' => 0,
                'degraded' => 0,
                'incidents' => 0,
                'last_healthy' => true,
            ];
        }

        $payload['total'] = (int) ($payload['total'] ?? 0) + 1;

        if (! $healthy) {
            $payload['degraded'] = (int) ($payload['degraded'] ?? 0) + 1;

            if (($payload['last_healthy'] ?? true) === true) {
                $payload['incidents'] = (int) ($payload['incidents'] ?? 0) + 1;
            }
        }

        $payload['last_healthy'] = $healthy;

        Cache::put($key, $payload, now()->addDays(2));
    }

    private function uptimeCacheKey(string $date): string
    {
        return self::UPTIME_CACHE_PREFIX.$date;
    }
}
