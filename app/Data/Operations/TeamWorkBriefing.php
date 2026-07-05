<?php

namespace App\Data\Operations;

readonly class TeamWorkBriefing
{
    /**
     * @param  array<string, int>  $supportBySlot
     */
    public function __construct(
        public string $date,
        public array $supportBySlot,
        public int $followUpCount,
        public int $priorityCount,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'support_by_slot' => $this->supportBySlot,
            'follow_up_count' => $this->followUpCount,
            'priority_count' => $this->priorityCount,
        ];
    }
}
