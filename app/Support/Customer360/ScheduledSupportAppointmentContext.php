<?php

namespace App\Support\Customer360;

use App\Enums\SupportAppointmentStatus;
use App\Models\Incident;
use App\Models\SupportAppointment;
use Illuminate\Support\Carbon;

class ScheduledSupportAppointmentContext
{
    /**
     * @return array{
     *     status: SupportAppointmentStatus,
     *     preferred_date: Carbon,
     *     preferred_time_slot: \App\Enums\SupportAppointmentTimeSlot|null,
     *     time_slot_label: ?string,
     *     created_at: Carbon|null,
     *     updated_at: Carbon|null,
     *     completed_at: ?Carbon,
     *     assignee_name: ?string,
     *     is_active: bool,
     *     is_completed: bool,
     * }|null
     */
    public function forIncident(Incident $incident): ?array
    {
        $incident->loadMissing([
            'supportAppointments',
            'assignee',
        ]);

        $appointment = $this->resolveAppointment($incident);

        if (! $appointment instanceof SupportAppointment) {
            return null;
        }

        $isCompleted = $appointment->status === SupportAppointmentStatus::Completed;

        return [
            'status' => $appointment->status,
            'preferred_date' => $appointment->preferred_date,
            'preferred_time_slot' => $appointment->preferred_time_slot,
            'time_slot_label' => $appointment->preferred_time_slot?->label(),
            'created_at' => $appointment->created_at,
            'updated_at' => $appointment->updated_at,
            'completed_at' => $isCompleted ? $appointment->updated_at : null,
            'assignee_name' => $incident->assignee?->firstName() ?: $incident->assignee?->name,
            'is_active' => $appointment->status === SupportAppointmentStatus::Scheduled,
            'is_completed' => $isCompleted,
        ];
    }

    private function resolveAppointment(Incident $incident): ?SupportAppointment
    {
        $scheduled = $incident->supportAppointments
            ->filter(fn (SupportAppointment $appointment): bool => $appointment->isScheduled())
            ->sort(function (SupportAppointment $left, SupportAppointment $right): int {
                $dateCompare = $right->preferred_date <=> $left->preferred_date;

                return $dateCompare !== 0 ? $dateCompare : $right->id <=> $left->id;
            })
            ->first();

        if ($scheduled instanceof SupportAppointment) {
            return $scheduled;
        }

        return $incident->supportAppointments
            ->sort(function (SupportAppointment $left, SupportAppointment $right): int {
                $updatedCompare = ($right->updated_at?->timestamp ?? 0) <=> ($left->updated_at?->timestamp ?? 0);

                return $updatedCompare !== 0 ? $updatedCompare : $right->id <=> $left->id;
            })
            ->first();
    }
}
