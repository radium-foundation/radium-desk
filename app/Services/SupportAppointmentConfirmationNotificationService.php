<?php

namespace App\Services;

use App\Data\NotificationMessage;
use App\Enums\NotificationType;
use App\Enums\SupportAppointmentBookingSource;
use App\Enums\WhatsAppTemplate;
use App\Models\SupportAppointment;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\AppDateFormatter;
use Illuminate\Support\Facades\Log;
use Throwable;

class SupportAppointmentConfirmationNotificationService
{
    public function __construct(
        private readonly NotificationDispatcher $notificationDispatcher,
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

        try {
            $this->notificationDispatcher->send(
                NotificationType::SupportAppointmentBooked,
                new NotificationMessage(
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
                ),
            );
        } catch (Throwable $exception) {
            Log::error('support_appointment.confirmation.failed', [
                'appointment_id' => $appointment->id,
                'incident_id' => $incident->id,
                'booking_source' => $bookingSource->value,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
