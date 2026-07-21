<?php

namespace App\Services\Performance;

use App\Infrastructure\Queue\QueueMetricsService;
use App\Services\HybridRealtime\HybridRealtimeFeature;
use App\Services\HybridRealtime\HybridRealtimeFeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PerformanceHealthService
{
    public function __construct(
        private readonly PerformanceRuntimeConfig $runtimeConfig,
        private readonly HybridRealtimeFeatureService $hybridRealtime,
        private readonly QueueMetricsService $queueMetrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $snapshot = $this->queueMetrics->latest();
        $pendingJobs = $snapshot?->pendingJobs ?? $this->countPendingJobs();
        $failedJobs = $snapshot?->failedJobs ?? $this->countFailedJobs();

        return [
            'hybrid_realtime_features' => $this->enabledHybridRealtimeFeatures(),
            'dashboard_poll_interval_ms' => $this->runtimeConfig->dashboardPollIntervalMs(),
            'notification_poll_interval_ms' => $this->runtimeConfig->notificationPollIntervalMs(),
            'broadcast_driver' => (string) config('broadcasting.default', 'null'),
            'dashboard_live_mode' => (string) config('dashboard.live_mode', 'auto'),
            'queue_pending_jobs' => $pendingJobs,
            'failed_jobs' => $failedJobs,
            'cpu_load' => $this->cpuLoad(),
            'memory' => $this->memoryUsage(),
            'websocket_status' => 'Not monitored (future)',
            'performance_profile' => app(PerformanceSettingsService::class)->currentProfile(),
        ];
    }

    /**
     * @return list<array{feature: string, label: string, enabled: bool, wired: bool}>
     */
    private function enabledHybridRealtimeFeatures(): array
    {
        $features = [];

        foreach (HybridRealtimeFeature::all() as $feature) {
            $definition = config("hybrid_realtime.features.{$feature}", []);
            $settingKey = $definition['setting_key'] ?? $feature;

            $features[] = [
                'feature' => $feature,
                'label' => $this->featureLabel($settingKey),
                'enabled' => $this->hybridRealtime->enabled($feature),
                'wired' => (bool) ($definition['wired'] ?? false),
            ];
        }

        return $features;
    }

    private function featureLabel(string $settingKey): string
    {
        $definition = config("system_settings.settings.{$settingKey}", []);

        return (string) ($definition['label'] ?? $settingKey);
    }

    private function countPendingJobs(): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        return (int) DB::table('jobs')->whereNull('reserved_at')->count();
    }

    private function countFailedJobs(): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return (int) DB::table('failed_jobs')->count();
    }

    /**
     * @return array{available: bool, load: string|null}
     */
    private function cpuLoad(): array
    {
        if (! function_exists('sys_getloadavg')) {
            return ['available' => false, 'load' => null];
        }

        $load = sys_getloadavg();

        if (! is_array($load) || $load === []) {
            return ['available' => false, 'load' => null];
        }

        return [
            'available' => true,
            'load' => implode(' / ', array_map(fn (float $value): string => number_format($value, 2), $load)),
        ];
    }

    /**
     * @return array{current: string, peak: string}
     */
    private function memoryUsage(): array
    {
        return [
            'current' => $this->formatBytes(memory_get_usage(true)),
            'peak' => $this->formatBytes(memory_get_peak_usage(true)),
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return number_format($value, $unitIndex === 0 ? 0 : 1).' '.$units[$unitIndex];
    }
}
