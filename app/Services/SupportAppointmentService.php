<?php

namespace App\Services;

use App\Enums\SupportAppointmentBookingSource;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Operations\SupportAppointmentSmartAssignmentService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class SupportAppointmentService
{
    public function __construct(
        private readonly SupportScheduleAvailabilityService $availabilityService,
    ) {}

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

        $existingAppointment = $this->findExistingBooking($incident, $validated);

        if ($existingAppointment !== null) {
            return $existingAppointment;
        }

        $appointment = SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            ...$validated,
        ]);

        try {
            app(SupportAppointmentConfirmationNotificationService::class)->send($appointment, $bookingSource);
        } catch (Throwable $exception) {
            Log::error('support_appointment.book.confirmation_unhandled', [
                'appointment_id' => $appointment->id,
                'incident_id' => $incident->id,
                'booking_source' => $bookingSource->value,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        try {
            app(SupportAppointmentSmartAssignmentService::class)->assignAfterBooking(
                incident: $incident,
                appointment: $appointment,
            );
        } catch (Throwable $exception) {
            Log::error('support_appointment.book.smart_assignment_unhandled', [
                'appointment_id' => $appointment->id,
                'incident_id' => $incident->id,
                'booking_source' => $bookingSource->value,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

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

        $validator->after(function ($validator) use ($data): void {
            $preferredDate = $data['preferred_date'] ?? null;

            if (! is_string($preferredDate) || $preferredDate === '') {
                return;
            }

            $dateMessage = $this->availabilityService->dateUnavailableMessage($preferredDate);

            if ($dateMessage !== null) {
                $validator->errors()->add('preferred_date', $dateMessage);

                return;
            }

            $timeSlotValue = $data['preferred_time_slot'] ?? null;

            if (! is_string($timeSlotValue) || $timeSlotValue === '') {
                return;
            }

            $timeSlot = SupportAppointmentTimeSlot::tryFrom($timeSlotValue);

            if ($timeSlot === null) {
                return;
            }

            $slotMessage = $this->availabilityService->timeSlotUnavailableMessage($preferredDate, $timeSlot);

            if ($slotMessage !== null) {
                $validator->errors()->add('preferred_time_slot', $slotMessage);
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function findExistingBooking(Incident $incident, array $validated): ?SupportAppointment
    {
        return SupportAppointment::query()
            ->where('incident_id', $incident->id)
            ->whereDate('preferred_date', $validated['preferred_date'])
            ->where('preferred_time_slot', $validated['preferred_time_slot'])
            ->where('phone_number', $validated['phone_number'])
            ->latest('id')
            ->first();
    }
}
