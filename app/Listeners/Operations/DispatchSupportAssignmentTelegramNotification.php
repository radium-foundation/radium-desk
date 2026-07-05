<?php

namespace App\Listeners\Operations;

use App\Data\NotificationMessage;
use App\Enums\NotificationType;
use App\Events\Operations\SupportAppointmentSmartAssigned;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Legacy NotificationDispatcher hook for smart-assignment Telegram delivery.
 *
 * @deprecated Superseded by {@see DispatchIraSmartAssignmentNotification} and
 *             {@see \App\Services\Operations\IraCommunicationService}. This listener
 *             is intentionally not registered in {@see \App\Providers\AppServiceProvider}
 *             to avoid duplicate Telegram messages on {@see SupportAppointmentSmartAssigned}.
 *
 * Retained for reference and unit-test compatibility. Do not re-register without
 * first removing the Ira communication path.
 */
class DispatchSupportAssignmentTelegramNotification
{
    public function __construct(
        private readonly NotificationDispatcher $notificationDispatcher,
    ) {}

    public function handle(SupportAppointmentSmartAssigned $event): void
    {
        try {
            $incident = $event->incident->loadMissing('order');
            $appointment = $event->appointment;
            $order = $incident->order;

            $this->notificationDispatcher->send(
                NotificationType::SupportAppointmentAssigned,
                new NotificationMessage(
                    type: NotificationType::SupportAppointmentAssigned,
                    customer: $order?->customer_name ?? 'Customer',
                    incident: $incident,
                    subject: 'New Support Assigned',
                    variables: [
                        'customer' => $order?->customer_name ?? 'Unknown',
                        'device' => $order?->device_model ?? $order?->product_name ?? 'Unknown',
                        'slot' => $appointment->preferred_time_slot?->label() ?? 'Unknown',
                        'case' => $incident->reference_no,
                        'assigned_to' => $event->assignee->name,
                    ],
                    metadata: [
                        'appointment_id' => $appointment->id,
                        'assignee_id' => $event->assignee->id,
                        'assignment_reason' => $event->result->context,
                    ],
                ),
            );
        } catch (Throwable $exception) {
            Log::error('smart_assignment.telegram_dispatch_failed', [
                'incident_id' => $event->incident->id,
                'appointment_id' => $event->appointment->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
