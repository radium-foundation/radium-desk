<?php

namespace App\Data;

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
        ];
    }
}
