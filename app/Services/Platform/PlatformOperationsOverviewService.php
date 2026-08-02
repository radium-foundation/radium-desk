<?php

namespace App\Services\Platform;

use App\Enums\PlatformHealthStatus;
use App\Data\Executive\ExecutiveMetricDto;
use App\ReadModels\Executive\ExecutiveKpiReadModel;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Operational summaries for Platform Operations Overview (no workflow UI).
 */
class PlatformOperationsOverviewService
{
    public const CACHE_KEY = PlatformCachePolicy::KEY_OPERATIONS_OVERVIEW;

    public const CACHE_TTL_SECONDS = PlatformCachePolicy::TTL_PRIORITY_3;

    public function __construct(
        private readonly ExecutiveKpiReadModel $executiveKpis,
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
            $items = [
                $this->kpiItem('open_cases', 'Open Cases', $this->executiveKpis->get('open_cases')),
                $this->kpiItem('critical_cases', 'Critical Cases', $this->executiveKpis->get('critical_cases')),
                $this->kpiItem('customers_waiting', 'Customers Waiting', $this->executiveKpis->get('customers_waiting')),
                $this->kpiItem('refund_queue', 'Refund Queue', $this->executiveKpis->get('refund_queue')),
                $this->kpiItem('active_agents', 'Active Agents', $this->executiveKpis->get('active_agents')),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'items' => [],
                'overall_status' => PlatformHealthStatus::Critical->value,
                'generated_at' => now()->toIso8601String(),
                'available' => false,
                'links' => $this->links(),
            ];
        }

        $statuses = array_map(
            static fn (array $item): PlatformHealthStatus => PlatformHealthStatus::tryFrom((string) $item['status'])
                ?? PlatformHealthStatus::Healthy,
            $items,
        );
        $overall = PlatformHealthStatus::worst(...$statuses);

        $payload = [
            'items' => $items,
            'overall_status' => $overall->value,
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
    private function kpiItem(string $key, string $label, ExecutiveMetricDto $kpi): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $kpi->status->value,
            'status_label' => $kpi->status->label(),
            'badge_class' => $kpi->status->badgeClass(),
            'summary' => $kpi->formattedValue,
            'updated_at' => $kpi->lastUpdated->toIso8601String(),
        ];
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    public function links(): array
    {
        return [
            ['label' => 'Operations Control Center', 'url' => route('admin.operations.index')],
            ['label' => 'Today', 'url' => route('admin.operations.index', ['hub_tab' => 'today'])],
            ['label' => 'Ready Queue', 'url' => route('dashboard')],
            ['label' => 'Workforce', 'url' => route('workforce.index')],
            ['label' => 'Attendance', 'url' => route('workforce-management.attendance.index')],
            ['label' => 'Service Cases', 'url' => route('incidents.index')],
            ['label' => 'Refunds', 'url' => route('refunds.index', ['status' => 'pending'])],
        ];
    }
}
