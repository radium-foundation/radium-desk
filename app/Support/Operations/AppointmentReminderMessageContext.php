<?php

namespace App\Support\Operations;

use App\Models\Incident;
use App\Models\User;

final class AppointmentReminderMessageContext
{
    public static function appointmentTypeLabel(?Incident $incident): ?string
    {
        if ($incident === null) {
            return null;
        }

        if (filled($incident->category)) {
            return trim((string) $incident->category);
        }

        if (filled($incident->title)) {
            return trim((string) $incident->title);
        }

        return null;
    }

    public static function engineerDisplayName(User $engineer): string
    {
        $firstName = trim($engineer->firstName());

        if ($firstName !== '') {
            return $firstName;
        }

        return trim((string) $engineer->name);
    }
}
