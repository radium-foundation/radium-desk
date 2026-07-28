<?php

namespace App\Data;

readonly class TeamActivityPanel
{
    /**
     * @param  list<TeamActivityAgentRow>  $agents
     */
    public function __construct(
        public array $agents,
        public bool $empty,
        public int $ivrCallsTotalToday = 0,
    ) {}

    public function agentCount(): int
    {
        return count($this->agents);
    }

    public static function empty(): self
    {
        return new self([], true);
    }
}
