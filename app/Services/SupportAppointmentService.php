<?php

namespace App\Services;

use App\Enums\SupportAppointmentBookingSource;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Operations\SupportAppointmentSmartAssignmentService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
        $normalizedPhone = $this->normalizePhoneNumber($validated['phone_number']);

        /** @var array{appointment: SupportAppointment, should_notify: bool} $result */
        $result = DB::transaction(function () use ($incident, $validated, $normalizedPhone): array {
            Incident::query()->whereKey($incident->id)->lockForUpdate()->first();

            $existingAppointment = $this->findActiveScheduledBooking(
                incidentId: $incident->id,
                preferredDate: $validated['preferred_date'],
                timeSlot: $validated['preferred_time_slot'],
                normalizedPhone: $normalizedPhone,
            );

            if ($existingAppointment !== null) {
                return [
                    'appointment' => $existingAppointment,
                    'should_notify' => false,
                ];
            }

            SupportAppointment::query()
                ->where('incident_id', $incident->id)
                ->where('status', SupportAppointmentStatus::Scheduled)
                ->update(['status' => SupportAppointmentStatus::Superseded]);

            try {
                $appointment = SupportAppointment::query()->create([
                    'incident_id' => $incident->id,
                    'preferred_date' => $validated['preferred_date'],
                    'preferred_time_slot' => $validated['preferred_time_slot'],
                    'phone_number' => $validated['phone_number'],
                    'normalized_phone' => $normalizedPhone,
                    'additional_notes' => $validated['additional_notes'] ?? null,
                    'status' => SupportAppointmentStatus::Scheduled,
                ]);
            } catch (UniqueConstraintViolationException|QueryException $exception) {
                if (! $this->isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }

                $existingAppointment = $this->findActiveScheduledBooking(
                    incidentId: $incident->id,
                    preferredDate: $validated['preferred_date'],
                    timeSlot: $validated['preferred_time_slot'],
                    normalizedPhone: $normalizedPhone,
                );

                if ($existingAppointment === null) {
                    $existingAppointment = SupportAppointment::query()
                        ->where('incident_id', $incident->id)
                        ->where('status', SupportAppointmentStatus::Scheduled)
                        ->latest('id')
                        ->first();
                }

                if ($existingAppointment === null) {
                    throw $exception;
                }

                return [
                    'appointment' => $existingAppointment,
                    'should_notify' => false,
                ];
            }

            return [
                'appointment' => $appointment,
                'should_notify' => true,
            ];
        });

        if ($result['should_notify']) {
            $this->sendConfirmationNotification($result['appointment'], $incident, $bookingSource);
            $this->assignAfterBooking($result['appointment'], $incident, $bookingSource);
        }

        return $result['appointment'];
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

    public function normalizePhoneNumber(string $phoneNumber): string
    {
        return preg_replace('/\D+/', '', $phoneNumber) ?? '';
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

    private function findActiveScheduledBooking(
        int $incidentId,
        string $preferredDate,
        string $timeSlot,
        string $normalizedPhone,
    ): ?SupportAppointment {
        return SupportAppointment::query()
            ->where('incident_id', $incidentId)
            ->where('status', SupportAppointmentStatus::Scheduled)
            ->whereDate('preferred_date', $preferredDate)
            ->where('preferred_time_slot', $timeSlot)
            ->where('normalized_phone', $normalizedPhone)
            ->latest('id')
            ->first();
    }

    private function sendConfirmationNotification(
        SupportAppointment $appointment,
        Incident $incident,
        SupportAppointmentBookingSource $bookingSource,
    ): void {
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
    }

    private function assignAfterBooking(
        SupportAppointment $appointment,
        Incident $incident,
        SupportAppointmentBookingSource $bookingSource,
    ): void {
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
    }

    private function isUniqueConstraintViolation(QueryException|UniqueConstraintViolationException $exception): bool
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return true;
        }

        $sqlState = $exception->errorInfo[0] ?? null;

        return in_array($sqlState, ['23000', '23505', '2067'], true);
    }
}
