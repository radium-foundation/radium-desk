<?php

namespace App\Services\Operations;

use App\Data\Operations\TeamWorkBriefing;
use App\Enums\OperationQueue;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;

class TeamWorkBriefingService
{
    public function __construct(
        private readonly OperationsRoleService $roleService,
    ) {}

    public function buildFor(User $user, ?Carbon $at = null): TeamWorkBriefing
    {
        $at ??= now();
        $snapshot = DashboardSnapshot::load();

        return new TeamWorkBriefing(
            date: $at->toDateString(),
            supportBySlot: $this->supportCountsBySlot($user, $at, $snapshot),
            followUpCount: $snapshot->incidentsForQueue(OperationQueue::WaitingCustomer->value, $user)->count(),
            priorityCount: $snapshot->incidentsForQueue(OperationQueue::Attention->value, $user)->count(),
        );
    }

    /**
     * @return list<User>
     */
    public function recipients(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', [
                ...$this->roleService->operationalRoleSlugs(),
            ]))
            ->get()
            ->filter(fn (User $user): bool => $this->roleService->usesSupportQueues($user))
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function supportCountsBySlot(User $user, Carbon $at, DashboardSnapshot $snapshot): array
    {
        $counts = [
            SupportAppointmentTimeSlot::Morning->value => 0,
            SupportAppointmentTimeSlot::Afternoon->value => 0,
            SupportAppointmentTimeSlot::Evening->value => 0,
        ];

        $today = $at->copy()->startOfDay();

        $snapshot->incidentsForQueue(OperationQueue::Scheduled->value, $user)
            ->each(function (Incident $incident) use (&$counts, $today): void {
                foreach ($incident->supportAppointments as $appointment) {
                    if ($appointment->preferred_date === null
                        || ! $appointment->preferred_date->isSameDay($today)
                        || $appointment->preferred_time_slot === null) {
                        continue;
                    }

                    $slot = $appointment->preferred_time_slot->value;
                    $counts[$slot] = ($counts[$slot] ?? 0) + 1;
                }
            });

        return $counts;
    }
}
