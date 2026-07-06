<?php

namespace App\Services\Automation\Handlers;

use App\Contracts\Automation\ActionHandler;
use App\Data\Automation\ActionHandlerResult;
use App\Data\Automation\PlannedAutomationAction;
use App\Enums\AutomationPolicyActionType;
use App\Services\Automation\CustomerWaitingLifecycleService;

class AutoCloseActionHandler implements ActionHandler
{
    public function __construct(
        private readonly CustomerWaitingLifecycleService $customerWaitingLifecycleService,
    ) {}

    public function supports(AutomationPolicyActionType $type): bool
    {
        return $type === AutomationPolicyActionType::AutoClose;
    }

    public function handle(PlannedAutomationAction $action): ActionHandlerResult
    {
        return match ($action->actionKey) {
            'customer_not_responding',
            'close_case_no_response' => $this->customerWaitingLifecycleService->autoCloseForNoResponse($action),
            default => ActionHandlerResult::failure("No auto-close handler exists for action key [{$action->actionKey}]."),
        };
    }
}
