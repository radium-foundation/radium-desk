<?php

namespace App\Services;

use App\Data\NotificationMessage;
use App\Enums\NotificationType;
use App\Enums\SupportAppointmentBookingSource;
use App\Enums\WhatsAppTemplate;
use App\Models\Incident;
use App\Models\SupportAppointment;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\AppDateFormatter;
use Illuminate\Support\Facades\Log;
use Throwable;

class SupportAppointmentConfirmationNotificationService
{
    public function __construct(
        private readonly NotificationDispatcher $notificationDispatcher,
        private readonly NotificationAuditTrailService $auditTrail,
    ) {}

    public function send(
        SupportAppointment $appointment,
        SupportAppointmentBookingSource $bookingSource,
    ): void {
        $appointment->loadMissing('incident.order');

        $incident = $appointment->incident;
        $order = $incident?->order;

        if ($incident === null || $order === null) {
            Log::warning('support_appointment.confirmation.skipped', [
                'appointment_id' => $appointment->id,
                'reason' => 'missing_incident_or_order',
            ]);

            return;
        }

        $customerName = trim((string) ($order->customer_name ?? ''));
        $customerName = $customerName !== '' ? $customerName : 'Customer';
        $preferredDate = AppDateFormatter::date($appointment->preferred_date) ?? $appointment->preferred_date->format('d M Y');
        $preferredTimeSlot = $appointment->preferred_time_slot->label();

        $message = new NotificationMessage(
            type: NotificationType::SupportAppointmentBooked,
            customer: $order,
            incident: $incident,
            template: WhatsAppTemplate::SupportAppointmentBooked->value,
            variables: [
                'customer_name' => $customerName,
                'order_id' => (string) $order->order_id,
                'preferred_date' => $preferredDate,
                'preferred_time_slot' => $preferredTimeSlot,
            ],
            metadata: [
                'source' => $bookingSource->notificationSource(),
                'trigger_source' => $bookingSource->whatsAppTriggerSource()->value,
                'body_values' => [
                    $customerName,
                    (string) $order->order_id,
                    $preferredDate,
                    $preferredTimeSlot,
                ],
                'support_appointment_id' => $appointment->id,
            ],
        );

        try {
            $this->notificationDispatcher->send(
                NotificationType::SupportAppointmentBooked,
                $message,
            );
        } catch (Throwable $exception) {
            $this->recordFailure($appointment, $incident, $bookingSource, $message, $exception);
        }
    }

    private function recordFailure(
        SupportAppointment $appointment,
        Incident $incident,
        SupportAppointmentBookingSource $bookingSource,
        NotificationMessage $message,
        Throwable $exception,
    ): void {
        Log::error('support_appointment.confirmation.failed', [
            'appointment_id' => $appointment->id,
            'incident_id' => $incident->id,
            'booking_source' => $bookingSource->value,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        try {
            $this->auditTrail->recordUnhandledFailure($message, $exception);
        } catch (Throwable $auditException) {
            Log::error('support_appointment.confirmation.audit_failed', [
                'appointment_id' => $appointment->id,
                'incident_id' => $incident->id,
                'booking_source' => $bookingSource->value,
                'exception' => $auditException::class,
                'message' => $auditException->getMessage(),
            ]);
        }
    }
}
