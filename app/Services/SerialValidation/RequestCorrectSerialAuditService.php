<?php

namespace App\Services\SerialValidation;

use App\Data\SerialInsight;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

class RequestCorrectSerialAuditService
{
    public const EVENT_REQUEST_SENT = 'serial.correct_serial_request_sent';

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function recordRequestSent(
        Incident $incident,
        Order $order,
        User $actor,
        SerialInsight $insight,
        ?Request $request = null,
    ): AuditLog {
        return $this->auditLogService->log(
            userId: $actor->id,
            event: self::EVENT_REQUEST_SENT,
            auditable: $incident,
            oldValues: [
                'serial_number' => $order->serial_number,
            ],
            newValues: [
                'incident_id' => $incident->id,
                'order_id' => $order->order_id,
                'old_serial' => $order->serial_number,
                'reason' => $insight->technicalReason,
                'confidence' => $insight->confidence->value,
                'insight_status' => $insight->status->value,
                'sent_by' => $actor->name,
            ],
            request: $request,
        );
    }
}
