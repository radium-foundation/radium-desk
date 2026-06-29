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
            subtitle: (string) config('automation.subtitle', 'AI Assistant'),
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
}
