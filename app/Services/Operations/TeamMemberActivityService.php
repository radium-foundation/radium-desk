<?php

namespace App\Services\Operations;

use App\Models\User;

class TeamMemberActivityService
{
    public function recordActive(User $user): void
    {
        $this->touch($user, 'last_active_at');
    }

    public function recordCaseAction(User $user): void
    {
        $this->touch($user, 'last_case_action_at');
        $this->recordActive($user);
    }

    public function recordCustomerCommunication(User $user): void
    {
        $this->touch($user, 'last_customer_communication_at');
        $this->recordActive($user);
    }

    public function recordStatusChange(User $user): void
    {
        $this->touch($user, 'last_status_change_at');
        $this->recordCaseAction($user);
    }

    /**
     * @return array<string, string|null>
     */
    public function snapshotFor(User $user): array
    {
        return [
            'last_active_at' => $user->last_active_at?->toIso8601String(),
            'last_case_action_at' => $user->last_case_action_at?->toIso8601String(),
            'last_customer_communication_at' => $user->last_customer_communication_at?->toIso8601String(),
            'last_status_change_at' => $user->last_status_change_at?->toIso8601String(),
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
