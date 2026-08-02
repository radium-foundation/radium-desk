<?php

namespace App\Services\Platform;

use App\Enums\PlatformHealthStatus;
use App\Infrastructure\Queue\QueueMetricsService;
use App\Services\Bonvoice\BonvoiceAnalyticsService;
use App\Services\Operations\OperationsAutomationMetricsService;
use App\Services\Operations\OperationsNotificationMetricsService;
use App\Services\Operations\OperationsQueueMetricsService;
use App\Services\Platform\PlatformCachePolicy;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cacheable Performance zone summaries. Reuses Ops metrics services.
 */
class PlatformPerformanceOverviewService
{
    public const CACHE_KEY = PlatformCachePolicy::KEY_PERFORMANCE_OVERVIEW;

    public const CACHE_TTL_SECONDS = PlatformCachePolicy::TTL_PRIORITY_3;

    public const ITEM_KEYS = ['queue', 'notifications', 'automation', 'ivr'];

    public function __construct(
        private readonly OperationsQueueMetricsService $queueMetrics,
        private readonly OperationsNotificationMetricsService $notificationMetrics,
        private readonly OperationsAutomationMetricsService $automationMetrics,
        private readonly QueueMetricsService $infraQueueMetrics,
        private readonly BonvoiceAnalyticsService $bonvoiceAnalytics,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, overall_status: string, generated_at: ?string, available: bool}
     */
    public function cachedOverview(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && isset($cached['items'])) {
            return [
                'items' => $cached['items'],
                'overall_status' => (string) ($cached['overall_status'] ?? PlatformHealthStatus::Disabled->value),
                'generated_at' => isset($cached['generated_at']) ? (string) $cached['generated_at'] : null,
                'available' => true,
            ];
        }

        return [
            'items' => array_map(fn (string $key): array => $this->loadingItem($key), self::ITEM_KEYS),
            'overall_status' => PlatformHealthStatus::Disabled->value,
            'generated_at' => null,
            'available' => false,
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, overall_status: string, generated_at: string, available: bool}
     */
    public function overview(bool $useCache = true): array
    {
        if ($useCache) {
            $cached = $this->cachedOverview();
            if ($cached['available']) {
                return $cached + ['available' => true];
            }
        }

        $items = [
            $this->safeItem('queue', fn () => $this->queueItem()),
            $this->safeItem('notifications', fn () => $this->notificationsItem()),
            $this->safeItem('automation', fn () => $this->automationItem()),
            $this->safeItem('ivr', fn () => $this->ivrItem()),
        ];

        $statuses = array_map(
            static fn (array $item): PlatformHealthStatus => PlatformHealthStatus::tryFrom((string) ($item['platform_status'] ?? ''))
                ?? PlatformHealthStatus::Disabled,
            $items,
        );
        $overall = PlatformHealthStatus::worst(...$statuses);
        $payload = [
            'items' => $items,
            'overall_status' => $overall->value,
            'generated_at' => now()->toIso8601String(),
            'available' => true,
        ];

        Cache::put(self::CACHE_KEY, $payload, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(string $key): array
    {
        return match ($key) {
            'queue' => [
                'key' => 'queue',
                'label' => 'Queue Metrics',
                'partial' => 'admin.operations.partials.queue-metrics',
                'metrics' => $this->queueMetrics->metrics(),
            ],
            'notifications' => [
                'key' => 'notifications',
                'label' => 'Notification Metrics',
                'partial' => 'admin.operations.partials.notification-metrics',
                'metrics' => $this->notificationMetrics->metrics(),
            ],
            'automation' => [
                'key' => 'automation',
                'label' => 'Automation Metrics',
                'partial' => 'admin.operations.partials.automation-metrics',
                'metrics' => $this->automationMetrics->metrics(),
            ],
            'ivr' => [
                'key' => 'ivr',
                'label' => 'IVR Health',
                'partial' => 'admin.operations.partials.ivr-health',
                'health' => $this->bonvoiceAnalytics->widgets()['ivr_health'] ?? [],
            ],
            default => abort(404),
        };
    }

    public function isKnownKey(string $key): bool
    {
        return in_array($key, self::ITEM_KEYS, true);
    }

    /**
     * @param  callable(): array<string, mixed>  $builder
     * @return array<string, mixed>
     */
    private function safeItem(string $key, callable $builder): array
    {
        try {
            return $builder();
        } catch (Throwable $exception) {
            report($exception);

            return [
                'key' => $key,
                'label' => $this->labelFor($key),
                'status' => 'unavailable',
                'status_label' => 'Unavailable',
                'badge_class' => 'dark',
                'platform_status' => PlatformHealthStatus::Critical->value,
                'summary' => 'Failed to refresh. Last known state retained when available.',
                'updated_at' => now()->toIso8601String(),
                'available' => false,
                'retryable' => true,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function queueItem(): array
    {
        $latest = $this->infraQueueMetrics->latest();
        $metrics = $latest !== null
            ? [
                'pending' => $latest->pendingJobs,
                'failed' => $latest->failedJobs,
                'running' => 0,
                'retries' => 0,
            ]
            : $this->queueMetrics->metrics();

        $failed = (int) ($metrics['failed'] ?? 0);
        $pending = (int) ($metrics['pending'] ?? 0);
        $status = match (true) {
            $failed > 0 => PlatformHealthStatus::Critical,
            $pending > 50 => PlatformHealthStatus::Warning,
            default => PlatformHealthStatus::Healthy,
        };

        return $this->item(
            'queue',
            'Queue',
            $status,
            sprintf('%d pending · %d failed', $pending, $failed),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationsItem(): array
    {
        $metrics = $this->notificationMetrics->metrics();
        $failed = (int) ($metrics['failed_today'] ?? 0);
        $sent = (int) ($metrics['sent_today'] ?? 0);
        $status = $failed > 0 ? PlatformHealthStatus::Warning : PlatformHealthStatus::Healthy;

        return $this->item(
            'notifications',
            'Notifications',
            $status,
            sprintf('%d sent today · %d failed', $sent, $failed),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function automationItem(): array
    {
        $metrics = $this->automationMetrics->metrics();
        $failed = (int) ($metrics['failed'] ?? 0);
        $executions = (int) ($metrics['executions_today'] ?? 0);
        $status = $failed > 0 ? PlatformHealthStatus::Warning : PlatformHealthStatus::Healthy;

        return $this->item(
            'automation',
            'Automation Throughput',
            $status,
            sprintf('%d executions today · %d failed', $executions, $failed),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function ivrItem(): array
    {
        $widgets = $this->bonvoiceAnalytics->widgets();
        $health = $widgets['ivr_health'] ?? [];
        $statusValue = (string) ($health['status'] ?? $health['overall_status'] ?? 'healthy');
        $status = match (true) {
            str_contains($statusValue, 'fail') || str_contains($statusValue, 'critical') => PlatformHealthStatus::Critical,
            str_contains($statusValue, 'warn') => PlatformHealthStatus::Warning,
            default => PlatformHealthStatus::Healthy,
        };

        return $this->item(
            'ivr',
            'IVR',
            $status,
            (string) ($health['detail'] ?? $health['summary'] ?? 'IVR call performance.'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $key, string $label, PlatformHealthStatus $status, string $summary): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status->value,
            'status_label' => $status->label(),
            'badge_class' => $status->badgeClass(),
            'platform_status' => $status->value,
            'summary' => $summary,
            'updated_at' => now()->toIso8601String(),
            'available' => true,
            'retryable' => false,
            'expandable' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadingItem(string $key): array
    {
        return [
            'key' => $key,
            'label' => $this->labelFor($key),
            'status' => 'loading',
            'status_label' => 'Loading',
            'badge_class' => 'info',
            'platform_status' => PlatformHealthStatus::Disabled->value,
            'summary' => 'Waiting for first refresh.',
            'updated_at' => null,
            'available' => false,
            'retryable' => false,
        ];
    }

    private function labelFor(string $key): string
    {
        return match ($key) {
            'queue' => 'Queue',
            'notifications' => 'Notifications',
            'automation' => 'Automation Throughput',
            'ivr' => 'IVR',
            default => $key,
        };
    }
}
