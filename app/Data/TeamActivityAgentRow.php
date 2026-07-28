<?php

namespace App\Data;

use App\Enums\OperationsKpiProfile;
use App\Enums\TeamActivityStatus;
use Illuminate\Support\Carbon;

readonly class TeamActivityAgentRow
{
    /**
     * @param  list<TeamActivityEntry>  $history
     */
    public function __construct(
        public int $id,
        public string $name,
        public TeamActivityStatus $status,
        public string $statusLabel,
        public string $statusTone,
        public ?string $workingLabel,
        public ?string $overtimeLabel,
        public int $todayCount,
        public ?TeamActivityEntry $latest,
        public array $history = [],
        public bool $expanded = false,
        public bool $isVirtual = false,
        public ?string $badge = null,
        public ?Carbon $latestActivityAt = null,
        public ?string $calendarBadge = null,
        public ?string $todayDurationLabel = null,
        public ?string $currentDurationLabel = null,
        public ?int $sessionsToday = null,
        public ?int $supplementaryKpiCount = null,
        public ?string $supplementaryKpiLabel = null,
        public ?OperationsKpiProfile $kpiProfile = null,
        public ?string $outcomeLabel = null,
        public ?int $outcomeCount = null,
        public ?string $effortLabel = null,
        public ?int $effortCount = null,
        /** @var array<string, int|float|null>|null */
        public ?array $kpiBreakdown = null,
        public ?int $callsAnsweredToday = null,
        public ?int $callsTotalToday = null,
        public ?string $callsTalkDurationLabel = null,
        public ?int $pendingCasesCount = null,
        public ?int $overdueCasesCount = null,
        public ?Carbon $previousActivityAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'status_label' => $this->statusLabel,
            'status_tone' => $this->statusTone,
            'working_label' => $this->workingLabel,
            'overtime_label' => $this->overtimeLabel,
            'today_count' => $this->todayCount,
            'latest' => $this->latest?->toArray(),
            'history' => array_map(
                static fn (TeamActivityEntry $entry): array => $entry->toArray(),
                $this->history,
            ),
            'expanded' => $this->expanded,
            'is_virtual' => $this->isVirtual,
            'badge' => $this->badge,
            'latest_activity_at' => $this->latestActivityAt?->toIso8601String(),
            'calendar_badge' => $this->calendarBadge,
            'today_duration_label' => $this->todayDurationLabel,
            'current_duration_label' => $this->currentDurationLabel,
            'sessions_today' => $this->sessionsToday,
            'supplementary_kpi_count' => $this->supplementaryKpiCount,
            'supplementary_kpi_label' => $this->supplementaryKpiLabel,
            'kpi_profile' => $this->kpiProfile?->value,
            'outcome_label' => $this->outcomeLabel,
            'outcome_count' => $this->outcomeCount,
            'effort_label' => $this->effortLabel,
            'effort_count' => $this->effortCount,
            'kpi_breakdown' => $this->kpiBreakdown,
            'calls_answered_today' => $this->callsAnsweredToday,
            'calls_total_today' => $this->callsTotalToday,
            'calls_talk_duration_label' => $this->callsTalkDurationLabel,
            'pending_cases_count' => $this->pendingCasesCount,
            'overdue_cases_count' => $this->overdueCasesCount,
            'previous_activity_at' => $this->previousActivityAt?->toIso8601String(),
        ];
    }
}
