<?php

namespace App\Data\Operations;

use App\Models\SupportAppointment;
use App\Models\User;
use Illuminate\Support\Carbon;

readonly class SupportAppointmentReminderCandidate
{
    public function __construct(
        public SupportAppointment $appointment,
        public User $engineer,
        public int $thresholdMinutes,
        public Carbon $startsAt,
    ) {}

    public function reminderLabel(): string
    {
        return match ($this->thresholdMinutes) {
            0 => 'Starting now',
            default => "Starts in {$this->thresholdMinutes} minutes",
        };
    }

    public function dedupeKey(): string
    {
        $date = $this->appointment->preferred_date?->toDateString() ?? $this->startsAt->toDateString();

        return sprintf(
            'appointment-reminder.%d.%d.%s',
            $this->appointment->id,
            $this->thresholdMinutes,
            $date,
        );
    }
}
