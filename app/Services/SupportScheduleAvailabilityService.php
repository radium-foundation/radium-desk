<?php

namespace App\Services;

use App\Enums\SupportAppointmentTimeSlot;
use Illuminate\Support\Carbon;

class SupportScheduleAvailabilityService
{
    public function timezone(): string
    {
        return (string) config('app.timezone', 'Asia/Kolkata');
    }

    public function now(): Carbon
    {
        return now()->timezone($this->timezone());
    }

    public function isDateBookable(Carbon|string $date): bool
    {
        $date = $this->normalizeDate($date);

        if ($date->isSunday()) {
            return false;
        }

        if ($date->isBefore($this->now()->startOfDay())) {
            return false;
        }

        if ($date->isSameDay($this->now()) && $this->availableTimeSlots($date) === []) {
            return false;
        }

        return true;
    }

    /**
     * @return list<SupportAppointmentTimeSlot>
     */
    public function availableTimeSlots(Carbon|string $date): array
    {
        $date = $this->normalizeDate($date);

        if ($date->isSunday()) {
            return [];
        }

        if ($date->isBefore($this->now()->startOfDay())) {
            return [];
        }

        return array_values(array_filter(
            SupportAppointmentTimeSlot::cases(),
            fn (SupportAppointmentTimeSlot $slot): bool => $this->isTimeSlotAvailable($date, $slot),
        ));
    }

    public function isTimeSlotAvailable(Carbon|string $date, SupportAppointmentTimeSlot $slot): bool
    {
        $date = $this->normalizeDate($date);

        if ($date->isSunday()) {
            return false;
        }

        if ($date->isBefore($this->now()->startOfDay())) {
            return false;
        }

        if (! $date->isSameDay($this->now())) {
            return true;
        }

        $cutoff = match ($slot) {
            SupportAppointmentTimeSlot::Morning => $date->copy()->setTime(11, 0),
            SupportAppointmentTimeSlot::Afternoon => $date->copy()->setTime(14, 0),
            SupportAppointmentTimeSlot::Evening => $date->copy()->setTime(17, 0),
        };

        return $this->now()->lt($cutoff);
    }

    public function dateUnavailableMessage(Carbon|string $date): ?string
    {
        $date = $this->normalizeDate($date);

        if ($date->isSunday()) {
            return 'Appointments are not available on Sundays. Please choose another date.';
        }

        if ($date->isBefore($this->now()->startOfDay())) {
            return 'Please choose today or a future date.';
        }

        if ($date->isSameDay($this->now()) && $this->availableTimeSlots($date) === []) {
            return 'No time slots remain available for today. Please choose another date.';
        }

        return null;
    }

    public function nextBookableDate(?Carbon $from = null): Carbon
    {
        $date = ($from ?? $this->now())->copy()->startOfDay()->addDay();

        while (! $this->isDateBookable($date)) {
            $date->addDay();
        }

        return $date;
    }

    public function timeSlotUnavailableMessage(Carbon|string $date, SupportAppointmentTimeSlot $slot): ?string
    {
        if ($this->isTimeSlotAvailable($date, $slot)) {
            return null;
        }

        $date = $this->normalizeDate($date);

        if ($date->isSunday()) {
            return 'Appointments are not available on Sundays.';
        }

        if ($date->isSameDay($this->now())) {
            return 'This time slot is no longer available for today. Please choose another slot or date.';
        }

        return 'The selected time slot is not available for this date.';
    }

    /**
     * @return array<string, list<string>>
     */
    public function frontendAvailabilityConfig(): array
    {
        $slots = [];

        foreach (SupportAppointmentTimeSlot::cases() as $slot) {
            $slots[$slot->value] = $slot->label();
        }

        return [
            'timezone' => $this->timezone(),
            'today' => $this->now()->toDateString(),
            'slots' => $slots,
            'cutoffs' => [
                SupportAppointmentTimeSlot::Morning->value => '11:00',
                SupportAppointmentTimeSlot::Afternoon->value => '14:00',
                SupportAppointmentTimeSlot::Evening->value => '17:00',
            ],
            'sundayUnavailableMessage' => 'Appointments are not available on Sundays. Please choose another date.',
            'sameDayUnavailableMessage' => 'No time slots remain available for today. Please choose another date.',
        ];
    }

    private function normalizeDate(Carbon|string $date): Carbon
    {
        if ($date instanceof Carbon) {
            return $date->copy()->timezone($this->timezone())->startOfDay();
        }

        return Carbon::parse($date, $this->timezone())->startOfDay();
    }
}
