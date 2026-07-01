<?php

namespace App\Data\Automation;

use App\Enums\AutomationPolicyActionType;
use App\Models\IncidentWaitingState;
use Illuminate\Support\Carbon;

readonly class PlannedAutomationAction
{
    /**
     * @param  array<string, mixed>  $actionConfig
     */
    public function __construct(
        public IncidentWaitingState $waitingState,
        public string $policyKey,
        public int $scheduleStep,
        public AutomationPolicyActionType $actionType,
        public string $actionKey,
        public ?string $channel,
        public Carbon $scheduledAt,
        public array $actionConfig = [],
    ) {}
}
