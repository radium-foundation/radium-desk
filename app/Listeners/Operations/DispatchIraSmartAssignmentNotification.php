<?php

namespace App\Listeners\Operations;

use App\Events\Operations\SupportAppointmentSmartAssigned;
use App\Services\Operations\IraAssignmentTelegramBatchService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ira-owned Telegram delivery for smart assignment events.
 *
 * Batches IRA assignments per engineer with a short delay to reduce notification fatigue.
 */
class DispatchIraSmartAssignmentNotification
{
    public function __construct(
        private readonly IraAssignmentTelegramBatchService $assignmentTelegramBatchService,
    ) {}

    public function handle(SupportAppointmentSmartAssigned $event): void
    {
        try {
            $incident = $event->incident->loadMissing('order');

            $this->assignmentTelegramBatchService->enqueue(
                assignee: $event->assignee,
                incident: $incident,
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
