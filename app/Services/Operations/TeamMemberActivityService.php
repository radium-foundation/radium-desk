<?php

namespace App\Services\Operations;

use App\Enums\PresenceActivityType;
use App\Models\User;
use Illuminate\Support\Carbon;

class TeamMemberActivityService
{
    public function __construct(
        private readonly PresenceEngineService $presenceEngine,
        private readonly WorkforceActivityContextService $workforceActivityContextService,
    ) {}

    public function recordSystemActivity(User $user): void
    {
        $this->touch($user, 'last_active_at');
    }

    public function recordActive(User $user): void
    {
        $this->recordSystemActivity($user);
        $this->presenceEngine->recordActivity($user, PresenceActivityType::System);
    }

    public function recordCaseAction(User $user): void
    {
        $this->touch($user, 'last_case_action_at');
        $this->recordSystemActivity($user);
        $this->presenceEngine->recordActivity($user, PresenceActivityType::CaseAction);
        $this->workforceActivityContextService->touchBusinessAction($user, 'case.action');
    }

    public function recordCustomerCommunication(User $user): void
    {
        $this->touch($user, 'last_customer_communication_at');
        $this->recordSystemActivity($user);
        $this->presenceEngine->recordActivity($user, PresenceActivityType::CustomerCommunication);
        $this->workforceActivityContextService->touchBusinessAction($user, 'communication.sent');
    }

    public function recordStatusChange(User $user): void
    {
        $this->touch($user, 'last_status_change_at');
        $this->recordCaseAction($user);
        $this->presenceEngine->recordActivity($user, PresenceActivityType::StatusChange);
        $this->workforceActivityContextService->touchBusinessAction($user, 'service_case.status_changed');
    }

    public function lastWorkActivityAt(User $user): ?Carbon
    {
        return collect([
            $user->last_customer_communication_at,
            $user->last_case_action_at,
            $user->last_status_change_at,
        ])
            ->filter()
            ->max();
    }

    /**
     * @return array{label: string, at: Carbon}|null
     */
    public function primaryWorkActivity(User $user): ?array
    {
        $candidates = collect([
            [
                'label' => 'Communication Sent',
                'at' => $user->last_customer_communication_at,
            ],
            [
                'label' => 'Case Updated',
                'at' => $user->last_case_action_at,
            ],
            [
                'label' => 'Status Changed',
                'at' => $user->last_status_change_at,
            ],
        ])
            ->filter(fn (array $entry): bool => $entry['at'] instanceof Carbon)
            ->sortByDesc(fn (array $entry): int => $entry['at']->getTimestamp())
            ->values();

        $primary = $candidates->first();

        if ($primary === null) {
            return null;
        }

        return [
            'label' => $primary['label'],
            'at' => $primary['at'],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function snapshotFor(User $user): array
    {
        $workActivity = $this->primaryWorkActivity($user);

        return [
            'last_active_at' => $user->last_active_at?->toIso8601String(),
            'last_system_activity_at' => $user->last_active_at?->toIso8601String(),
            'last_case_action_at' => $user->last_case_action_at?->toIso8601String(),
            'last_customer_communication_at' => $user->last_customer_communication_at?->toIso8601String(),
            'last_status_change_at' => $user->last_status_change_at?->toIso8601String(),
            'last_work_activity_at' => $this->lastWorkActivityAt($user)?->toIso8601String(),
            'primary_work_activity_label' => $workActivity['label'] ?? null,
            'primary_work_activity_at' => isset($workActivity['at'])
                ? $workActivity['at']->toIso8601String()
                : null,
        ];
    }

    private function touch(User $user, string $column): void
    {
        User::query()
            ->whereKey($user->id)
            ->update([$column => now()]);

        $user->setAttribute($column, now());
    }
}
