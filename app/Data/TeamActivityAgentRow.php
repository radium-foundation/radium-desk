<?php

namespace App\Data;

use App\Enums\TeamActivityStatus;

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
        ];
    }
}
