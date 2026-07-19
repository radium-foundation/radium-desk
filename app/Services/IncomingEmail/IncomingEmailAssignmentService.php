<?php

namespace App\Services\IncomingEmail;

use App\Models\Incident;
use App\Models\User;
use App\Notifications\HighPriorityServiceCaseNotification;
use App\Services\Assignment\UniversalAssignmentEngine;
use App\Services\SettingService;
use Illuminate\Support\Carbon;

class IncomingEmailAssignmentService
{
    public function __construct(
        private readonly UniversalAssignmentEngine $assignmentEngine,
        private readonly SettingService $settingService,
    ) {}

    public function assignIfUnassigned(Incident $incident, User $actor, ?Carbon $at = null): Incident
    {
        $at ??= now();
        $incident = $incident->fresh(['assignee', 'order']);

        if ($incident->assigned_to_user_id !== null) {
            return $incident;
        }

        $incident = $this->assignmentEngine->assignForCommunicationIntake($incident, $actor, $at);
        $this->notifyHighPriorityIfNeeded($incident, $actor);

        return $incident;
    }

    private function notifyHighPriorityIfNeeded(Incident $incident, User $actor): void
    {
        $incident = $incident->fresh(['assignee']);

        if (! $incident->high_priority
            || $incident->assignee === null
            || ! $incident->assignee->is_active
            || $incident->assignee->trashed()
            || ! $this->settingService->getBool('notifications.high_priority_enabled', true)) {
            return;
        }

        $incident->assignee->notify(new HighPriorityServiceCaseNotification($incident, $actor));
    }
}
