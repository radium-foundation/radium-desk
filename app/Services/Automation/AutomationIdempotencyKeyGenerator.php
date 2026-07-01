<?php

namespace App\Services\Automation;

use App\Enums\AutomationPolicyActionType;

class AutomationIdempotencyKeyGenerator
{
    public function generate(
        int $waitingStateId,
        string $policyKey,
        int $scheduleStep,
        AutomationPolicyActionType $actionType,
        ?string $channel = null,
    ): string {
        return sprintf(
            'automation.%d.%s.%d.%s.%s',
            $waitingStateId,
            $policyKey,
            $scheduleStep,
            $actionType->value,
            $channel ?? 'none',
        );
    }
}
