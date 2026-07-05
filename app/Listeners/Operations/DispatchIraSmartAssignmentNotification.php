<?php

namespace App\Listeners\Operations;

use App\Events\Operations\SupportAppointmentSmartAssigned;
use App\Services\Operations\IraCommunicationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ira-owned Telegram delivery for smart assignment events.
 *
 * @see IraCommunicationService Operational Telegram ownership boundary.
 */
class DispatchIraSmartAssignmentNotification
{
    public function __construct(
        private readonly IraCommunicationService $communicationService,
    ) {}

    public function handle(SupportAppointmentSmartAssigned $event): void
    {
        try {
            $incident = $event->incident->loadMissing('order');
            $appointment = $event->appointment;
            $order = $incident->order;

            $this->communicationService->sendSmartAssignment(
                assignee: $event->assignee,
                customer: $order?->customer_name ?? 'Unknown',
                device: $order?->device_model ?? $order?->product_name ?? 'Unknown',
                time: $appointment->preferred_time_slot?->label() ?? 'Unknown',
                caseReference: $incident->reference_no,
                context: [
                    'appointment_id' => $appointment->id,
                    'incident_id' => $incident->id,
                    'assignment_reason' => $event->result->context,
                ],
            );
        } catch (Throwable $exception) {
            Log::error('ira.smart_assignment.telegram_dispatch_failed', [
                'incident_id' => $event->incident->id,
                'appointment_id' => $event->appointment->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
