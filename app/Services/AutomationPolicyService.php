<?php

namespace App\Services;

use App\Data\AutomationPolicyDefinition;
use App\Data\AutomationPolicyAction;
use App\Data\AutomationPolicyDueAction;
use App\Enums\AutomationPolicyActionType;
use App\Exceptions\InvalidAutomationPolicyException;
use App\Exceptions\UnknownAutomationPolicyException;
use App\Models\IncidentWaitingState;
use App\Services\Automation\CustomerWaitingEngagementService;
use App\Services\Automation\CustomerWaitingLifecycleService;
use Illuminate\Support\Carbon;

class AutomationPolicyService
{
    /**
     * @var array<string, AutomationPolicyDefinition>
     */
    private array $loadedPolicies = [];

    public function __construct(
        private readonly CustomerWaitingEngagementService $engagementService,
    ) {}

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
                if (! $this->actionIsDue($waitingState, $action, $referenceAt)) {
                    continue;
                }

                $dueActions[] = new AutomationPolicyDueAction(
                    day: $entry->day,
                    scheduledAt: $scheduledAt,
                    action: $action,
                );
            }
        }

        return $dueActions;
    }

    private function actionIsDue(
        IncidentWaitingState $waitingState,
        AutomationPolicyAction $action,
        Carbon $referenceAt,
    ): bool {
        if ($waitingState->reminder_policy_key !== 'customer_waiting_default') {
            return true;
        }

        if ($action->type !== AutomationPolicyActionType::AutoClose
            || $action->key !== 'customer_not_responding') {
            return true;
        }

        $followupSentAt = $waitingState->customer_followup_sent_at;

        if ($followupSentAt === null) {
            return false;
        }

        if (! CustomerWaitingLifecycleService::isAutoCloseCutoffReached($followupSentAt, $referenceAt)) {
            return false;
        }

        $waitingState->loadMissing(['incident.order', 'incident.supportAppointments']);
        $incident = $waitingState->incident;

        if ($incident !== null && $this->engagementService->hasEngagement($incident, $waitingState)) {
            return false;
        }

        return true;
    }
}
