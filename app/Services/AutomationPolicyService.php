<?php

namespace App\Services;

use App\Data\AutomationPolicyDefinition;
use App\Data\AutomationPolicyDueAction;
use App\Exceptions\InvalidAutomationPolicyException;
use App\Exceptions\UnknownAutomationPolicyException;
use App\Models\IncidentWaitingState;
use Illuminate\Support\Carbon;

class AutomationPolicyService
{
    /**
     * @var array<string, AutomationPolicyDefinition>
     */
    private array $loadedPolicies = [];

    public function load(string $key): AutomationPolicyDefinition
    {
        if ($key === '') {
            throw UnknownAutomationPolicyException::forKey($key);
        }

        if (array_key_exists($key, $this->loadedPolicies)) {
            return $this->loadedPolicies[$key];
        }

        $config = config("automation_policies.policies.{$key}");
        if (! is_array($config)) {
            throw UnknownAutomationPolicyException::forKey($key);
        }

        $definition = AutomationPolicyDefinition::fromConfig($key, $config);
        $this->loadedPolicies[$key] = $definition;

        return $definition;
    }

    /**
     * @return list<AutomationPolicyDueAction>
     */
    public function dueActions(IncidentWaitingState $waitingState, Carbon $referenceAt): array
    {
        $policyKey = $waitingState->reminder_policy_key;
        if ($policyKey === null || $policyKey === '') {
            throw UnknownAutomationPolicyException::forKey((string) $policyKey);
        }

        $policy = $this->load($policyKey);
        $startedAt = $waitingState->started_at;

        if ($startedAt === null) {
            throw InvalidAutomationPolicyException::forKey($policyKey, 'waiting state started_at is required.');
        }

        if ($referenceAt->lt($startedAt)) {
            return [];
        }

        $dueActions = [];

        foreach ($policy->schedule as $entry) {
            $scheduledAt = $startedAt->copy()->addDays($entry->day);

            if ($scheduledAt->gt($referenceAt)) {
                continue;
            }

            foreach ($entry->actions as $action) {
                $dueActions[] = new AutomationPolicyDueAction(
                    day: $entry->day,
                    scheduledAt: $scheduledAt,
                    action: $action,
                );
            }
        }

        return $dueActions;
    }
}
