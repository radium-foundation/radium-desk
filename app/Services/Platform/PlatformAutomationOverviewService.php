<?php

namespace App\Services\Platform;

use App\Enums\PlatformHealthStatus;
use App\Services\Operations\AutomationHealthService;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cacheable Automation zone summary over AutomationHealthService.
 */
class PlatformAutomationOverviewService
{
    public const CACHE_KEY = 'platform:automation:overview';

    public const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly AutomationHealthService $automationHealth,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, overall_status: string, generated_at: ?string, available: bool, links: list<array{label: string, url: string}>}
     */
    public function cachedOverview(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
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

        try {
            $agg = $this->automationHealth->overviewAggregation();
        } catch (Throwable $exception) {
            report($exception);

            return [
                'items' => [[
                    'key' => 'automation_health',
                    'label' => 'Automation Health',
                    'status' => PlatformHealthStatus::Critical->value,
                    'status_label' => 'Unavailable',
                    'badge_class' => 'dark',
                    'summary' => 'Unable to refresh automation health.',
                    'updated_at' => now()->toIso8601String(),
                ]],
                'overall_status' => PlatformHealthStatus::Critical->value,
                'generated_at' => now()->toIso8601String(),
                'available' => false,
                'links' => $this->links(),
            ];
        }

        $statusValue = (string) ($agg['health_status'] ?? 'healthy');
        $status = PlatformHealthStatus::tryFrom($statusValue) ?? match ($statusValue) {
            'failed', 'critical' => PlatformHealthStatus::Critical,
            'warning' => PlatformHealthStatus::Warning,
            'disabled' => PlatformHealthStatus::Disabled,
            default => PlatformHealthStatus::Healthy,
        };

        $items = [
            [
                'key' => 'automation_health',
                'label' => 'Automation Health',
                'status' => $status->value,
                'status_label' => (string) ($agg['health_label'] ?? $status->label()),
                'badge_class' => $status->badgeClass(),
                'summary' => (string) ($agg['health_detail'] ?? sprintf(
                    '%d failures today · %d pending',
                    (int) ($agg['failures_today'] ?? 0),
                    (int) ($agg['pending_executions'] ?? 0),
                )),
                'updated_at' => now()->toIso8601String(),
                'expandable' => true,
            ],
            [
                'key' => 'scheduler',
                'label' => 'Scheduler & Workers',
                'status' => $status->value,
                'status_label' => $status->label(),
                'badge_class' => $status->badgeClass(),
                'summary' => sprintf(
                    '%d executions today · last success %s',
                    (int) ($agg['executions_today'] ?? $agg['total_today'] ?? 0),
                    $agg['last_success_at'] ?? '—',
                ),
                'updated_at' => now()->toIso8601String(),
                'expandable' => true,
            ],
        ];

        $payload = [
            'items' => $items,
            'overall_status' => $status->value,
            'generated_at' => now()->toIso8601String(),
            'available' => true,
            'links' => $this->links(),
        ];

        Cache::put(self::CACHE_KEY, $payload, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(string $key): array
    {
        $agg = $this->automationHealth->overviewAggregation();

        return match ($key) {
            'automation_health' => [
                'key' => 'automation_health',
                'label' => 'Automation Health',
                'message' => (string) ($agg['health_detail'] ?? 'Open Automation Health for ledger and failure forensics.'),
                'links' => $this->links(),
            ],
            'scheduler' => [
                'key' => 'scheduler',
                'label' => 'Scheduler & Workers',
                'message' => sprintf(
                    '%d executions today · %d failures · %d pending',
                    (int) ($agg['executions_today'] ?? $agg['total_today'] ?? 0),
                    (int) ($agg['failures_today'] ?? 0),
                    (int) ($agg['pending_executions'] ?? 0),
                ),
                'links' => $this->links(),
            ],
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
}
