<?php

namespace App\Data\Workforce;

readonly class WorkforceMember360LeaveSection
{
    /**
     * @param  list<WorkforceMember360LeaveItem>  $upcoming
     * @param  list<WorkforceMember360LeaveItem>  $history
     */
    public function __construct(
        public bool $balanceAvailable,
        public string $balanceNote,
        public array $upcoming,
        public array $history,
    ) {}
}
