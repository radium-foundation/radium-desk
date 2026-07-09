<?php

namespace App\Services;

use App\Data\TimelineActor;
use App\Models\User;

class AutomationIdentityService
{
    public function resolve(?User $user): TimelineActor
    {
        if ($this->isAutomationActor($user)) {
            return $this->automationActor();
        }

        return new TimelineActor(
            displayName: $user?->firstName() ?? '',
        );
    }

    public function resolveWithRoleLabel(?User $user): TimelineActor
    {
        if ($this->isAutomationActor($user)) {
            return $this->automationActor();
        }

        $displayName = $user?->roleActorLabel() ?: $user?->firstName() ?? '';

        return new TimelineActor(
            displayName: $displayName,
        );
    }

    public function automationActor(): TimelineActor
    {
        return new TimelineActor(
            displayName: (string) config('automation.display_name', 'Ira'),
            subtitle: (string) config('automation.subtitle', 'IRA AI'),
            isAutomation: true,
        );
    }

    public function isAutomationActor(?User $user): bool
    {
        if ($user === null) {
            return true;
        }

        $systemEmail = (string) config('cashfree.system_user_email');

        if ($systemEmail === '') {
            return false;
        }

        return strcasecmp($user->email, $systemEmail) === 0;
    }

    public function isExcludedFromManualAssignment(User $user): bool
    {
        if ($this->isAutomationActor($user)) {
            return true;
        }

        $email = strtolower(trim((string) $user->email));

        if ($email === '') {
            return true;
        }

        if (str_starts_with($email, 'demo@')) {
            return true;
        }

        foreach (config('service_case_assignment.manual_assign_excluded_emails', []) as $excludedEmail) {
            if (strcasecmp($email, strtolower(trim((string) $excludedEmail))) === 0) {
                return true;
            }
        }

        return false;
    }

    public function systemUser(): User
    {
        $systemEmail = (string) config('cashfree.system_user_email');

        if ($systemEmail !== '') {
            $user = User::query()->where('email', $systemEmail)->first();

            if ($user !== null) {
                return $user;
            }
        }

        return User::query()->firstOrFail();
    }
}
