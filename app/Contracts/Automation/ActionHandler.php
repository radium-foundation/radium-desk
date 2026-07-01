<?php

namespace App\Contracts\Automation;

use App\Data\Automation\ActionHandlerResult;
use App\Data\Automation\PlannedAutomationAction;
use App\Enums\AutomationPolicyActionType;

interface ActionHandler
{
    public function supports(AutomationPolicyActionType $type): bool;

    public function handle(PlannedAutomationAction $action): ActionHandlerResult;
}
