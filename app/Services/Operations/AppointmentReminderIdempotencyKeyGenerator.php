<?php

namespace App\Services\Operations;

class AppointmentReminderIdempotencyKeyGenerator
{
    public function generate(int $appointmentId, int $thresholdMinutes, string $scheduledDate): string
    {
        return sprintf(
            'appointment-reminder.%d.%d.%s',
            $appointmentId,
            $thresholdMinutes,
            $scheduledDate,
        );
    }
}
