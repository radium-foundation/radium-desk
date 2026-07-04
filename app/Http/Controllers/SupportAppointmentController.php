<?php

namespace App\Http\Controllers;

use App\Enums\SupportAppointmentTimeSlot;
use App\Http\Requests\StoreSupportAppointmentRequest;
use App\Models\Incident;
use App\Models\SupportAppointment;
use App\Services\SupportAppointmentService;
use App\Services\SupportScheduleAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

class SupportAppointmentController extends Controller
{
    public function __construct(
        private readonly SupportAppointmentService $supportAppointmentService,
        private readonly SupportScheduleAvailabilityService $availabilityService,
    ) {}

    public function create(Request $request, Incident $incident): View
    {
        $incident->loadMissing('order');

        return view('support-appointments.create', [
            'incident' => $incident,
            'order' => $incident->order,
            'timeSlots' => SupportAppointmentTimeSlot::cases(),
            'availabilityConfig' => $this->availabilityService->frontendAvailabilityConfig(),
            'todayAvailableSlots' => $this->availabilityService->availableTimeSlots(
                $this->availabilityService->now(),
            ),
            'formAction' => URL::temporarySignedRoute(
                'support-appointments.store',
                now()->addDays(30),
                ['incident' => $incident->id],
            ),
        ]);
    }

    public function store(StoreSupportAppointmentRequest $request, Incident $incident): RedirectResponse
    {
        $appointment = $this->supportAppointmentService->book($incident, $request->validated());

        return redirect()->to(
            URL::temporarySignedRoute(
                'support-appointments.confirmation',
                now()->addHour(),
                [
                    'incident' => $incident->id,
                    'appointment' => $appointment->id,
                ],
            ),
        );
    }

    public function confirmation(Request $request, Incident $incident, SupportAppointment $appointment): View
    {
        abort_unless($appointment->incident_id === $incident->id, 404);

        $incident->loadMissing('order');

        return view('support-appointments.confirmation', [
            'incident' => $incident,
            'order' => $incident->order,
            'appointment' => $appointment,
        ]);
    }
}
