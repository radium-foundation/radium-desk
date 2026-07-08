<?php

namespace App\Services\Operations;

use App\Data\Operations\IraOperationalSnapshotData;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\PerformancePeriod;
use App\Enums\ServiceCaseSlaStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\IraOperationalMemorySnapshot;
use App\Models\Incident;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Dashboard\DashboardSnapshot;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class IraMemoryService
{
    private const SNAPSHOT_DATA_CACHE_TTL_SECONDS = 30;

    private ?IraOperationalSnapshotData $requestSnapshotData = null;

    public function __construct(
        private readonly TeamPerformanceMetricsService $performanceMetricsService,
        private readonly OperationsRoleService $roleService,
        private readonly WorkCalendarService $workCalendarService,
        private readonly TeamAvailabilityService $availabilityService,
        private readonly OperationsSupportIntelligenceService $supportIntelligenceService,
    ) {}

    public function capture(?Carbon $at = null): IraOperationalMemorySnapshot
    {
        $at ??= now();
        $data = $this->collectSnapshotData($at);
        $existing = IraOperationalMemorySnapshot::query()
            ->whereDate('snapshot_date', $at->toDateString())
            ->first();

        if ($existing !== null) {
            $existing->update([
                'operations' => $data->operations,
                'team' => $data->team,
                'performance' => $data->performance,
            ]);

            return $existing->refresh();
        }

        return IraOperationalMemorySnapshot::query()->create([
            'snapshot_date' => $at->toDateString(),
            'operations' => $data->operations,
            'team' => $data->team,
            'performance' => $data->performance,
        ]);
    }

    public function ensureTodaySnapshot(?Carbon $at = null): IraOperationalMemorySnapshot
    {
        $at ??= now();

        $existing = $this->snapshotForDate($at);

        if ($existing !== null) {
            return $existing;
        }

        return $this->capture($at);
    }

    public function snapshotForDate(Carbon $date): ?IraOperationalMemorySnapshot
    {
        return IraOperationalMemorySnapshot::query()
            ->whereDate('snapshot_date', $date->toDateString())
            ->first();
    }

    public function yesterdaySnapshot(?Carbon $at = null): ?IraOperationalMemorySnapshot
    {
        $at ??= now();

        return $this->snapshotForDate($at->copy()->subDay());
    }

    public function collectSnapshotData(?Carbon $at = null): IraOperationalSnapshotData
    {
        $at ??= now();

        if ($this->requestSnapshotData !== null) {
            return $this->requestSnapshotData;
        }

        $cacheKey = $this->snapshotDataCacheKey($at);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $this->requestSnapshotData = IraOperationalSnapshotData::fromArray($cached);
        }

        $data = $this->buildSnapshotData($at);

        Cache::put($cacheKey, $data->toArray(), now()->addSeconds(self::SNAPSHOT_DATA_CACHE_TTL_SECONDS));

        return $this->requestSnapshotData = $data;
    }

    public function invalidateSnapshotDataCache(?Carbon $at = null): void
    {
        Cache::forget($this->snapshotDataCacheKey($at ?? now()));
        $this->requestSnapshotData = null;
    }

    private function buildSnapshotData(Carbon $at): IraOperationalSnapshotData
    {
        $snapshot = DashboardSnapshot::load();
        $queueCounts = $snapshot->queueCounts();
        $slaCounts = $snapshot->slaCounts($at);
        $serviceSlaCounts = $snapshot->serviceSlaCounts($at);
        $hardwareSlaCounts = $snapshot->hardwareSlaCounts($at);
        $supportSummary = $this->supportIntelligenceService->summary($at);

        $operations = [
            'open_cases' => $snapshot->openCount(),
            'scheduled' => $queueCounts[OperationQueue::Scheduled->value] ?? 0,
            'scheduled_today' => $supportSummary->scheduledToday,
            'waiting' => $queueCounts[OperationQueue::WaitingCustomer->value] ?? 0,
            'serial_response_pending' => $supportSummary->serialStillWaiting,
            'overdue' => $serviceSlaCounts['overdue_cases'] ?? 0,
            'warning' => $serviceSlaCounts['warning_cases'] ?? 0,
            'hardware_overdue' => $hardwareSlaCounts['overdue_cases'] ?? 0,
            'hardware_warning' => $hardwareSlaCounts['warning_cases'] ?? 0,
            'missed_appointments' => $supportSummary->missedOverdue,
            'total_overdue_cases' => $slaCounts['overdue_cases'] ?? 0,
            'total_warning_cases' => $slaCounts['warning_cases'] ?? 0,
            'action_required' => $queueCounts[OperationQueue::ActionRequired->value] ?? 0,
            'attention' => $queueCounts[OperationQueue::Attention->value] ?? 0,
            'support' => $supportSummary->toArray(),
        ];

        $teamMembers = $this->teamMembers();
        $availableCount = 0;
        $leaveCount = 0;

        foreach ($teamMembers as $member) {
            if ($this->workCalendarService->hasApprovedLeave($member, $at)) {
                $leaveCount++;

                continue;
            }

            $status = $this->availabilityService->statusFor($member);

            if (in_array($status, [
                TeamAvailabilityStatus::Available,
                TeamAvailabilityStatus::Busy,
            ], true) && $this->workCalendarService->isEligibleForAssignment($member, $at)) {
                $availableCount++;
            }
        }

        $activeSessions = WorkSession::query()
            ->whereDate('work_date', $at->toDateString())
            ->whereIn('user_id', $teamMembers->pluck('id'))
            ->get();

        $averageActiveSeconds = $activeSessions->isEmpty()
            ? 0
            : (int) round($activeSessions->avg('active_duration_seconds'));

        $team = [
            'available' => $availableCount,
            'leave' => $leaveCount,
            'total_members' => $teamMembers->count(),
            'average_active_seconds' => $averageActiveSeconds,
        ];

        $performance = $this->teamPerformanceTotals($at);

        return new IraOperationalSnapshotData(
            date: $at->toDateString(),
            operations: $operations,
            team: $team,
            performance: $performance,
        );
    }

    private function snapshotDataCacheKey(Carbon $at): string
    {
        return 'ira:operations:snapshot-data:'.$at->toDateString();
    }

    /**
     * @return array<string, int|float>
     */
    public function compareWithYesterday(?Carbon $at = null): array
    {
        $at ??= now();
        $today = $this->collectSnapshotData($at);
        $yesterday = $this->yesterdaySnapshot($at);

        if ($yesterday === null) {
            return [];
        }

        $yesterdayData = IraOperationalSnapshotData::fromModel($yesterday);
        $deltas = [];

        foreach (['operations', 'team', 'performance'] as $section) {
            foreach ($today->{$section} as $key => $value) {
                if (! is_numeric($value)) {
                    continue;
                }

                $previous = $yesterdayData->{$section}[$key] ?? null;

                if (! is_numeric($previous)) {
                    continue;
                }

                $deltas["{$section}.{$key}"] = (float) $value - (float) $previous;
            }
        }

        return $deltas;
    }

    public function pruneOldSnapshots(?Carbon $at = null): int
    {
        $at ??= now();
        $retentionDays = max(1, (int) config('ira.memory.retention_days', 90));
        $cutoff = $at->copy()->subDays($retentionDays)->toDateString();

        return IraOperationalMemorySnapshot::query()
            ->whereDate('snapshot_date', '<', $cutoff)
            ->delete();
    }

    /**
     * @return array<string, int|float>
     */
    private function teamPerformanceTotals(Carbon $at): array
    {
        $completedCases = 0;
        $slaSuccessTotal = 0.0;
        $slaEvaluated = 0;
        $communications = 0;

        foreach ($this->performanceMetricsService->teamMetrics(PerformancePeriod::Today, null, null, $at) as $metrics) {
            $completedCases += (int) ($metrics->customerWork['completed_cases'] ?? 0);
            $communications += (int) ($metrics->customerWork['customer_communications'] ?? 0);

            $slaPercentage = $metrics->quality['sla_success_percentage'] ?? null;

            if ($slaPercentage !== null) {
                $slaSuccessTotal += (float) $slaPercentage;
                $slaEvaluated++;
            }
        }

        return [
            'completed_cases' => $completedCases,
            'sla_percentage' => $slaEvaluated > 0 ? round($slaSuccessTotal / $slaEvaluated, 1) : 100.0,
            'customer_communications' => $communications,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function teamMembers()
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $this->roleService->operationalRoleSlugs()))
            ->get();
    }
}
