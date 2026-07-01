<?php

namespace App\Data;

readonly class AutomationPolicyScheduleEntry
{
    /**
     * @param  list<AutomationPolicyAction>  $actions
     */
    public function __construct(
        public int $day,
        public array $actions,
    ) {}
}
