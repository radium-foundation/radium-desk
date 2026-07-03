<?php

namespace App\Services;

use App\Enums\SupportAppointmentBookingSource;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\SupportAppointment;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class SupportAppointmentService
{
    /**
     * @return array<string, mixed>
     */
    public function bookingValidationRules(): array
    {
        return [
            'preferred_date' => ['required', 'date', 'after_or_equal:today'],
            'preferred_time_slot' => ['required', Rule::enum(SupportAppointmentTimeSlot::class)],
            'phone_number' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            'additional_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function book(
        Incident $incident,
        array $data,
        SupportAppointmentBookingSource $bookingSource = SupportAppointmentBookingSource::Web,
    ): SupportAppointment {
        $validated = $this->validateBookingData($data);

        $appointment = SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            ...$validated,
        ]);

        app(SupportAppointmentConfirmationNotificationService::class)->send($appointment, $bookingSource);

        return $appointment;
    }

    public function confirm(SupportAppointment $appointment): SupportAppointment
    {
        throw new LogicException('SupportAppointmentService::confirm() is not yet implemented.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reschedule(SupportAppointment $appointment, array $data): SupportAppointment
    {
        throw new LogicException('SupportAppointmentService::reschedule() is not yet implemented.');
    }

    public function cancel(SupportAppointment $appointment, ?string $reason = null): SupportAppointment
    {
        throw new LogicException('SupportAppointmentService::cancel() is not yet implemented.');
    }

    public function complete(SupportAppointment $appointment): SupportAppointment
    {
        throw new LogicException('SupportAppointmentService::complete() is not yet implemented.');
    }

    public function assignEngineer(SupportAppointment $appointment, User $engineer): SupportAppointment
    {
        throw new LogicException('SupportAppointmentService::assignEngineer() is not yet implemented.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateBookingData(array $data): array
    {
        $validator = Validator::make(
            data: $data,
            rules: $this->bookingValidationRules(),
            attributes: [
                'preferred_date' => 'preferred date',
                'preferred_time_slot' => 'preferred time slot',
                'phone_number' => 'phone number',
                'additional_notes' => 'additional notes',
            ],
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }
}
