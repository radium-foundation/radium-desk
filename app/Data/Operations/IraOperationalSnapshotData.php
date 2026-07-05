<?php

namespace App\Data\Operations;

readonly class IraOperationalSnapshotData
{
    /**
     * @param  array<string, int|float|null>  $operations
     * @param  array<string, int|float|null>  $team
     * @param  array<string, int|float|null>  $performance
     */
    public function __construct(
        public string $date,
        public array $operations,
        public array $team,
        public array $performance,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'operations' => $this->operations,
            'team' => $this->team,
            'performance' => $this->performance,
        ];
    }

    public static function fromModel(\App\Models\IraOperationalMemorySnapshot $snapshot): self
    {
        return new self(
            date: $snapshot->snapshot_date->toDateString(),
            operations: $snapshot->operations,
            team: $snapshot->team,
            performance: $snapshot->performance,
        );
    }
}
