<?php

namespace App\Services\MissingSerial;

use App\Models\Incident;
use App\Models\Order;
use App\Services\AuditLogService;

class MissingSerialAutomationAuditService
{
    public const EVENT_REQUEST_SENT = 'missing_serial.request_sent';

    public const EVENT_REMINDER_SENT = 'missing_serial.reminder_sent';

    public const EVENT_COMPLETED = 'missing_serial.completed';

    public const EVENT_ESCALATED = 'missing_serial.escalated';

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordRequestSent(Order $order, Incident $incident, array $context = []): void
    {
        $this->record(self::EVENT_REQUEST_SENT, $order, $incident, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordReminderSent(Order $order, Incident $incident, array $context = []): void
    {
        $this->record(self::EVENT_REMINDER_SENT, $order, $incident, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordCompleted(Order $order, string $reason, array $context = []): void
    {
        $this->auditLogService->log(
            userId: null,
            event: self::EVENT_COMPLETED,
            auditable: $order,
            newValues: array_merge([
                'reason' => $reason,
                'serial_number' => $order->serial_number,
            ], $context),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordEscalated(Order $order, Incident $incident, ?int $coordinatorUserId, array $context = []): void
    {
        $this->record(self::EVENT_ESCALATED, $order, $incident, array_merge([
            'coordinator_user_id' => $coordinatorUserId,
        ], $context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $event, Order $order, Incident $incident, array $context = []): void
    {
        $this->auditLogService->log(
            userId: null,
            event: $event,
            auditable: $order,
            newValues: array_merge([
                'order_id' => $order->order_id,
                'incident_id' => $incident->id,
            ], $context),
        );
    }
}
