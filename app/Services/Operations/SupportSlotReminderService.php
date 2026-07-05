<?php

namespace App\Services\Operations;

use App\Data\Operations\SupportSlotReminderItem;
use App\Enums\OperationQueue;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;

class SupportSlotReminderService
{
    public function __construct(
        private readonly TeamWorkBriefingService $briefingService,
    ) {}

    /**
     * @return list<SupportSlotReminderItem>
     */
    public function itemsFor(User $user, SupportAppointmentTimeSlot $slot, ?Carbon $at = null): array
    {
        $at ??= now();
        $today = $at->copy()->startOfDay();
        $snapshot = DashboardSnapshot::load();
        $items = [];

        $snapshot->incidentsForQueue(OperationQueue::Scheduled->value, $user)
            ->each(function (Incident $incident) use (&$items, $slot, $today): void {
                $order = $incident->order;

                foreach ($incident->supportAppointments as $appointment) {
                    if ($appointment->preferred_date === null
                        || ! $appointment->preferred_date->isSameDay($today)
                        || $appointment->preferred_time_slot !== $slot) {
                        continue;
                    }

                    $items[] = new SupportSlotReminderItem(
                        customerName: $order?->customer_name ?? 'Customer',
                        deviceModel: $order?->device_model ?? $order?->product_name ?? 'Unknown',
                    );
                }
            });

        return $items;
    }

    /**
     * @return list<User>
     */
    public function recipients(): array
    {
        return $this->briefingService->recipients();
    }
}
