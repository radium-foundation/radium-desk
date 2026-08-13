<?php

namespace App\Services\Operations;

use App\Data\Operations\ProductionCriticalAlert;
use App\Data\Platform\PlatformHealthSnapshot;
use App\Enums\AutomationExecutionStatus;
use App\Enums\OperationsHealthStatus;
use App\Enums\PlatformHealthStatus;
use App\Enums\QueueWorkerMode;
use App\Infrastructure\Queue\QueueDeadLetterCopy;
use App\Infrastructure\Queue\QueueMetricsService;
use App\Models\AutomationExecution;
use App\Models\BonvoiceWebhookLog;
use App\Models\CashfreeWebhookLog;
use App\Models\InteraktMessage;
use App\Models\InteraktWebhookLog;
use App\ReadModels\Integrations\CashfreeIntegrityReadModel;
use App\Services\Platform\Health\PlatformHealthSnapshotService;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ProductionWatchdogService
{
    private const UPTIME_CACHE_PREFIX = 'watchdog:uptime:';

    private const ERROR_SPIKE_WINDOW_MINUTES = 60;

    private const ERROR_SPIKE_THRESHOLD = 10;

    /** @var array<string, bool> */
    private array $tableExistsMemo = [];

    private bool $sharedHealthSnapshotResolved = false;

    private ?PlatformHealthSnapshot $sharedHealthSnapshot = null;

    public function __construct(
        private readonly CashfreeIntegrityReadModel $cashfreeIntegrityReadModel,
        private readonly OperationsIntegrationHealthService $integrationHealthService,
        private readonly OperationsSystemHealthService $systemHealthService,
        private readonly OperationsRadiumBoxHealthService $radiumBoxHealthService,
        private readonly PlatformHealthSnapshotService $platformHealthSnapshot,
        private readonly QueueMetricsService $queueMetrics,
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
        $missingSample = $this->cashfreeIntegrityReadModel->missingPaidOrderSample(5);
        $paidMissing = $missingSample['count'];

        if ($paidMissing > 0) {
            $alerts[] = new ProductionCriticalAlert(
                key: 'cashfree:paid_missing_order',
                label: 'Cashfree',
                message: sprintf(
                    '%d paid payment(s) have no matching Desk order.',
                    $paidMissing,
                ),
                affectedCount: $paidMissing,
                orderIds: $missingSample['order_ids'],
            );
        }

        $activeFailed = $this->cashfreeIntegrityReadModel->activeFailedWebhookCount();

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
        $alerts = [];

        $deadLetter = $this->queueDeadLetterAlert();
        if ($deadLetter !== null) {
            $alerts[] = $deadLetter;
        }

        $backlog = $this->queueBacklogAlert();
        if ($backlog !== null) {
            $alerts[] = $backlog;
        }

        return $alerts;
    }

    /**
     * Live failed_jobs identity — independent of snapshot freshness so a stale
     * Healthy Platform Health component cannot syncResolved-clear the DLQ alert.
     */
    private function queueDeadLetterAlert(): ?ProductionCriticalAlert
    {
        if (! QueueWorkerMode::fromConfig()->isActive()) {
            return null;
        }

        $uuids = $this->queueMetrics->failedJobUuids();
        if ($uuids === []) {
            return null;
        }

        $count = count($uuids);

        return new ProductionCriticalAlert(
            key: 'queue:dead_letter',
            label: 'Queue',
            message: QueueDeadLetterCopy::detail(QueueWorkerMode::fromConfig()->value, $count),
            affectedCount: $count,
            incidentIdentity: implode(',', $uuids),
        );
    }

    private function queueBacklogAlert(): ?ProductionCriticalAlert
    {
        $snapshot = $this->sharedHealthSnapshot();
        if ($snapshot !== null) {
            $queue = $snapshot->component('queue');
            if ($queue !== null) {
                if ($queue->status !== PlatformHealthStatus::Warning) {
                    return null;
                }

                return new ProductionCriticalAlert(
                    key: 'queue:backlog',
                    label: 'Queue',
                    message: $queue->detail !== ''
                        ? $queue->detail
                        : 'Queue backlog requires attention.',
                    affectedCount: (int) ($queue->metrics['pending_jobs'] ?? 0),
                );
            }
        }

        $component = $this->systemHealthService->componentFor('queue_worker');
        if ($component === null) {
            return null;
        }

        $status = OperationsHealthStatus::tryFrom((string) ($component['status'] ?? ''));

        if ($status === OperationsHealthStatus::Warning) {
            return new ProductionCriticalAlert(
                key: 'queue:backlog',
                label: 'Queue',
                message: (string) ($component['detail'] ?? 'Queue backlog requires attention.'),
            );
        }

        return null;
    }

    /**
     * @return list<ProductionCriticalAlert>
     */
    private function automationAlerts(): array
    {
        if (! $this->tableExists('automation_executions')) {
            return [];
        }

        $fromSnapshot = $this->automationAlertsFromSharedSnapshot();
        if ($fromSnapshot !== null) {
            return $fromSnapshot;
        }

        return $this->automationAlertsFromLedger();
    }

    /**
     * Prefer shared Platform Health automation component when warm.
     *
     * @return list<ProductionCriticalAlert>|null null = snapshot unavailable, use ledger path
     */
    private function automationAlertsFromSharedSnapshot(): ?array
    {
        $snapshot = $this->sharedHealthSnapshot();
        if ($snapshot === null) {
            return null;
        }

        $automation = $snapshot->component('automation');
        if ($automation === null) {
            return null;
        }

        // Critical only — Warning/Healthy still fall through to ledger so a stale
        // Healthy snapshot can never suppress new open failures (no semantic change).
        if ($automation->status !== PlatformHealthStatus::Critical) {
            return null;
        }

        $openFailures = (int) ($automation->metrics['critical_failures_today']
            ?? $automation->metrics['open_failures_24h']
            ?? 0);

        if ($openFailures < 1) {
            // Snapshot says Critical but metrics missing — fall back to ledger for accurate count.
            return null;
        }

        return [
            new ProductionCriticalAlert(
                key: 'automation:failures',
                label: 'Automation',
                message: sprintf(
                    '%d open automation failure(s) require attention.',
                    $openFailures,
                ),
                affectedCount: $openFailures,
            ),
        ];
    }

    /**
     * @return list<ProductionCriticalAlert>
     */
    private function automationAlertsFromLedger(): array
    {
        $threshold = max(1, (int) config('ira.watchdog.automation_failure_threshold', 3));
        $classifier = app(AutomationFailureClassifier::class);

        $openFailures = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Failed)
            ->where('created_at', '>=', now()->startOfDay())
            ->get(['id', 'error_message'])
            ->filter(fn (AutomationExecution $execution): bool => $classifier->isOpen($execution->error_message))
            ->count();

        if ($openFailures < $threshold) {
            return [];
        }

        return [
            new ProductionCriticalAlert(
                key: 'automation:failures',
                label: 'Automation',
                message: sprintf(
                    '%d open automation failure(s) require attention.',
                    $openFailures,
                ),
                affectedCount: $openFailures,
            ),
        ];
    }

    /**
     * @return list<ProductionCriticalAlert>
     */
    private function bonvoiceAlerts(): array
    {
        if (! $this->tableExists('bonvoice_webhook_logs')) {
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
        // Lean single-card lookup — full cards() also rebuilt Cashfree/Gmail/ZeptoMail/Telegram
        // (including up to 2000 audit_logs rows) on every 5-minute cron tick.
        $card = $this->integrationHealthService->card('interakt');

        if ($card !== null) {
            $status = OperationsHealthStatus::tryFrom((string) ($card['status'] ?? ''));

            if ($status === OperationsHealthStatus::Warning || $status === OperationsHealthStatus::Failed) {
                $recentFailures = 0;

                if ($this->tableExists('interakt_messages')) {
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
        }

        if ($this->tableExists('interakt_webhook_logs')) {
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
     * Probe /up in-process (same route semantics) instead of outbound HTTP with
     * timeout(10)->retry(2), which under CPU load timed out twice (~21s) and
     * amplified load via self-HTTP retries.
     *
     * @return list<ProductionCriticalAlert>
     */
    private function siteHealthAlerts(): array
    {
        $healthPath = '/up';

        try {
            /** @var HttpKernel $kernel */
            $kernel = app(HttpKernel::class);
            $request = Request::create($healthPath, 'GET');
            $response = $kernel->handle($request);
            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                return [];
            }
        } catch (\Throwable $e) {
            Log::warning('Production watchdog site health probe failed.', [
                'health_path' => $healthPath,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ]);

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
                    $status,
                ),
            ),
        ];
    }

    /**
     * @return list<ProductionCriticalAlert>
     */
    private function errorSpikeAlerts(): array
    {
        if (! $this->tableExists('cashfree_webhook_logs')) {
            return [];
        }

        $since = now()->subMinutes(self::ERROR_SPIKE_WINDOW_MINUTES);
        $spikeCount = CashfreeWebhookLog::query()
            ->where('processing_status', CashfreeWebhookLog::STATUS_FAILED)
            ->where('processed_at', '>=', $since)
            ->count();

        if ($this->tableExists('bonvoice_webhook_logs')) {
            $spikeCount += BonvoiceWebhookLog::query()
                ->where('processing_status', BonvoiceWebhookLog::STATUS_FAILED)
                ->where('received_at', '>=', $since)
                ->count();
        }

        if ($this->tableExists('interakt_webhook_logs')) {
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

    private function sharedHealthSnapshot(): ?PlatformHealthSnapshot
    {
        if (! $this->sharedHealthSnapshotResolved) {
            $this->sharedHealthSnapshot = $this->platformHealthSnapshot->current();
            $this->sharedHealthSnapshotResolved = true;
        }

        return $this->sharedHealthSnapshot;
    }

    private function tableExists(string $table): bool
    {
        return $this->tableExistsMemo[$table] ??= Schema::hasTable($table);
    }
}
