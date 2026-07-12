<?php

namespace App\Services\Dashboard;

use App\Data\Dashboard\AgentNextAppointment;
use App\Enums\OperationQueue;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Operations\TeamTelegramQuietRulesService;
use Illuminate\Support\Carbon;

class AgentNextAppointmentResolver
{
    public function __construct(
        private readonly TeamTelegramQuietRulesService $slotRules,
    ) {}

    public function resolve(DashboardSnapshot $snapshot, User $user, ?Carbon $now = null): ?AgentNextAppointment
    {
        $now ??= now();
        $today = $now->copy()->startOfDay();
        $candidates = [];

        $snapshot->incidentsForQueue(OperationQueue::Scheduled->value, $user)
            ->filter(fn (Incident $incident): bool => $incident->assigned_to_user_id === $user->id)
            ->each(function (Incident $incident) use (&$candidates, $today, $now): void {
                $order = $incident->order;

                foreach ($incident->supportAppointments as $appointment) {
                    if (! $appointment->isScheduled()
                        || $appointment->preferred_date === null
                        || ! $appointment->preferred_date->isSameDay($today)) {
                        continue;
                    }

                    $startsAt = $this->appointmentStartsAt($appointment, $today);

                    if ($startsAt === null) {
                        continue;
                    }

                    $candidates[] = new AgentNextAppointment(
                        incidentId: $incident->id,
                        customerName: $order?->customer_name ?? 'Customer',
                        deviceModel: $order?->device_model ?? $order?->product_name,
                        startsAt: $startsAt,
                        isOverdue: $startsAt->lt($now),
                    );
                }
            });

        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            fn (AgentNextAppointment $left, AgentNextAppointment $right): int => $left->startsAt <=> $right->startsAt,
        );

        foreach ($candidates as $candidate) {
            if ($candidate->startsAt->gte($now)) {
                return $candidate;
            }
        }

        return $candidates[array_key_last($candidates)];
    }

    private function appointmentStartsAt(SupportAppointment $appointment, Carbon $date): ?Carbon
    {
        $slot = $appointment->preferred_time_slot;

        if (! $slot instanceof SupportAppointmentTimeSlot) {
            return null;
        }

        return $this->slotRules->slotStartAt($slot, $date);
    }
}
