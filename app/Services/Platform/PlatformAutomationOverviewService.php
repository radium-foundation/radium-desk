<?php

namespace App\Services\Platform;

use App\Enums\PlatformHealthStatus;
use App\Services\Operations\AutomationHealthService;
use App\Services\Platform\Health\PlatformHealthSnapshotService;
use App\Services\Platform\Health\QueueHealthProvider;
use App\Services\Platform\Health\SchedulerHealthProvider;
use App\Services\Platform\PlatformCachePolicy;
use App\Support\Platform\PlatformCacheAudit;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cacheable Automation zone summary over AutomationHealthService.
 *
 * Scheduler & Workers is independent runtime health (heartbeat + queue probes),
 * not a mirror of the automation execution ledger.
 */
class PlatformAutomationOverviewService
{
    public const CACHE_KEY = PlatformCachePolicy::KEY_AUTOMATION_OVERVIEW;

    public const CACHE_TTL_SECONDS = PlatformCachePolicy::TTL_PRIORITY_3;

    public function __construct(
        private readonly AutomationHealthService $automationHealth,
        private readonly SchedulerHealthProvider $schedulerHealth,
        private readonly QueueHealthProvider $queueHealth,
        private readonly PlatformHealthSnapshotService $healthSnapshot,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, overall_status: string, generated_at: ?string, available: bool, links: list<array{label: string, url: string}>}
     */
    public function cachedOverview(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        PlatformCacheAudit::read(
            service: self::class,
            method: 'cachedOverview',
            cacheKey: self::CACHE_KEY,
            payload: is_array($cached) ? $cached : null,
            hit: is_array($cached) && isset($cached['items']),
        );
        if (is_array($cached) && isset($cached['items'])) {
            return $cached + ['available' => true];
        }

        return [
            'items' => [],
            'overall_status' => PlatformHealthStatus::Disabled->value,
            'generated_at' => null,
            'available' => false,
            'links' => $this->links(),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, overall_status: string, generated_at: string, available: bool, links: list<array{label: string, url: string}>}
     */
    public function overview(bool $useCache = true): array
    {
        if ($useCache) {
            $cached = $this->cachedOverview();
            if ($cached['available']) {
                return $cached;
            }
        }

        $schedulerItem = $this->schedulerWorkersItem();
        $schedulerStatus = PlatformHealthStatus::tryFrom((string) $schedulerItem['status'])
            ?? PlatformHealthStatus::Critical;

        try {
            $agg = $this->automationHealth->overviewAggregation();
        } catch (Throwable $exception) {
            report($exception);

            $items = [
                [
                    'key' => 'automation_health',
                    'label' => 'Automation Health',
                    'status' => PlatformHealthStatus::Critical->value,
                    'status_label' => 'Unavailable',
                    'badge_class' => 'dark',
                    'summary' => 'Unable to refresh automation health.',
                    'updated_at' => now()->toIso8601String(),
                ],
                $schedulerItem,
            ];

            return [
                'items' => $items,
                'overall_status' => PlatformHealthStatus::worst(
                    PlatformHealthStatus::Critical,
                    $schedulerStatus,
                )->value,
                'generated_at' => now()->toIso8601String(),
                'available' => false,
                'links' => $this->links(),
            ];
        }

        $statusValue = (string) ($agg['health_status'] ?? 'healthy');
        $automationStatus = PlatformHealthStatus::tryFrom($statusValue) ?? match ($statusValue) {
            'failed', 'critical' => PlatformHealthStatus::Critical,
            'warning' => PlatformHealthStatus::Warning,
            'disabled' => PlatformHealthStatus::Disabled,
            default => PlatformHealthStatus::Healthy,
        };

        $openFailures = (int) ($agg['open_failures_today'] ?? 0);
        $criticalFailures = (int) ($agg['critical_failures_today'] ?? 0);
        $historicalFailures = (int) ($agg['historical_failures_today'] ?? 0);

        $items = [
            [
                'key' => 'automation_health',
                'label' => 'Automation Health',
                'status' => $automationStatus->value,
                'status_label' => (string) ($agg['health_label'] ?? $automationStatus->label()),
                'badge_class' => $automationStatus->badgeClass(),
                'summary' => $this->automationHealthSummary($agg),
                'updated_at' => now()->toIso8601String(),
                'expandable' => true,
                'historical_failures_today' => $historicalFailures,
                'open_failures_today' => $openFailures,
                'critical_failures_today' => $criticalFailures,
            ],
            $schedulerItem,
        ];

        $payload = [
            'items' => $items,
            'overall_status' => PlatformHealthStatus::worst($automationStatus, $schedulerStatus)->value,
            'generated_at' => now()->toIso8601String(),
            'available' => true,
            'links' => $this->links(),
        ];

        $old = Cache::get(self::CACHE_KEY);
        PlatformCacheAudit::write(
            service: self::class,
            method: 'overview',
            cacheKey: self::CACHE_KEY,
            oldPayload: is_array($old) ? $old : null,
            newPayload: $payload,
        );
        Cache::put(self::CACHE_KEY, $payload, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(string $key): array
    {
        return match ($key) {
            'automation_health' => $this->automationHealthDiagnostics(),
            'scheduler' => $this->schedulerWorkersDiagnostics(),
            default => abort(404),
        };
    }

    public function isKnownKey(string $key): bool
    {
        return in_array($key, ['automation_health', 'scheduler'], true);
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    public function links(): array
    {
        return array_values(array_filter([
            ['label' => 'Automation Health', 'url' => route('admin.operations.automation-health')],
            ['label' => 'Automation Pipeline', 'url' => route('admin.automation.index')],
            [
                'label' => 'Ops Automation Hub',
                'url' => route('admin.operations.index', ['hub_tab' => 'automation']),
            ],
        ]));
    }

    /**
     * Runtime health only: scheduler heartbeat + queue worker probes.
     *
     * Reuses the Platform Health snapshot when present (same warm cycle) so
     * queue/jobs tables are not captured a second time.
     *
     * @return array<string, mixed>
     */
    private function schedulerWorkersItem(): array
    {
        $scheduler = null;
        $queue = null;

        $shared = $this->healthSnapshot->current();
        if ($shared !== null) {
            $scheduler = $shared->component('scheduler');
            $queue = $shared->component('queue');
        }

        $scheduler ??= $this->schedulerHealth->probe();
        $queue ??= $this->queueHealth->probe();

        $status = PlatformHealthStatus::worst($scheduler->status, $queue->status);

        return [
            'key' => 'scheduler',
            'label' => 'Scheduler & Workers',
            'status' => $status->value,
            'status_label' => $status->label(),
            'badge_class' => $status->badgeClass(),
            'summary' => $this->schedulerWorkersSummary($scheduler->detail, $queue->detail, $scheduler->metrics),
            'updated_at' => now()->toIso8601String(),
            'expandable' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulerWorkersDiagnostics(): array
    {
        $scheduler = $this->schedulerHealth->probe();
        $queue = $this->queueHealth->probe();

        return [
            'key' => 'scheduler',
            'label' => 'Scheduler & Workers',
            'message' => $this->schedulerWorkersSummary($scheduler->detail, $queue->detail, $scheduler->metrics),
            'links' => $this->links(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function automationHealthDiagnostics(): array
    {
        $agg = $this->automationHealth->overviewAggregation();

        return [
            'key' => 'automation_health',
            'label' => 'Automation Health',
            'message' => $this->automationHealthSummary($agg),
            'historical_failures_today' => (int) ($agg['historical_failures_today'] ?? 0),
            'open_failures_today' => (int) ($agg['open_failures_today'] ?? 0),
            'critical_failures_today' => (int) ($agg['critical_failures_today'] ?? 0),
            'failures_today' => (int) ($agg['failures_today'] ?? 0),
            'links' => $this->links(),
        ];
    }

    /**
     * @param  array<string, mixed>  $agg
     */
    private function automationHealthSummary(array $agg, ?PlatformHealthStatus $status = null): string
    {
        $historical = (int) ($agg['historical_failures_today'] ?? 0);
        $open = (int) ($agg['open_failures_today'] ?? 0);
        $critical = (int) ($agg['critical_failures_today'] ?? 0);

        $parts = [
            sprintf('Historical %d', $historical),
            sprintf('Open %d', $open),
            sprintf('Critical %d', $critical),
        ];

        $detail = (string) ($agg['health_detail'] ?? '');
        if ($detail !== '') {
            return implode(' · ', $parts).' — '.$detail;
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $schedulerMetrics
     */
    private function schedulerWorkersSummary(string $schedulerDetail, string $queueDetail, array $schedulerMetrics): string
    {
        $lastRun = $schedulerMetrics['last_run_at'] ?? null;
        $minutesAgo = $schedulerMetrics['minutes_ago'] ?? null;

        if (is_string($lastRun) && $lastRun !== '' && is_numeric($minutesAgo)) {
            $prefix = sprintf('Last scheduler run %d min ago', (int) $minutesAgo);
        } elseif (is_string($lastRun) && $lastRun !== '') {
            $prefix = 'Last scheduler run recorded';
        } else {
            $prefix = $schedulerDetail;
        }

        return $prefix.' · '.$queueDetail;
    }
}
