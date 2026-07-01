<?php

namespace App\Data;

use Illuminate\Support\Carbon;

readonly class AutomationPolicyDueAction
{
    public function __construct(
        public int $day,
        public Carbon $scheduledAt,
        public AutomationPolicyAction $action,
    ) {}
}
