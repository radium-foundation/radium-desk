<?php

namespace App\Services;

use App\Models\Incident;
use Illuminate\Support\Facades\URL;

class SupportAppointmentUrlService
{
    public function bookingUrl(Incident $incident): string
    {
        return URL::temporarySignedRoute(
            'support-appointments.create',
            now()->addDays(30),
            ['incident' => $incident->id],
        );
    }
}
