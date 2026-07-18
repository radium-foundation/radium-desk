<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncomingEmailMessageStatus;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogService;

class IncomingEmailHistoricalAssociationService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function associate(Order $order, IncomingEmailMessage $message, User $actor): IncomingEmailMessage
    {
        $message->update([
            'status' => IncomingEmailMessageStatus::HistoricalCustomer,
            'order_id' => $order->id,
            'incident_id' => null,
            'ignore_reason' => null,
            'processed_at' => now(),
            'processing_error' => null,
        ]);

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'incoming_email.historical_customer',
            auditable: $message->fresh(),
            newValues: [
                'order_id' => $order->id,
                'order_public_id' => $order->order_id,
                'mailbox' => $message->mailbox,
                'from_email' => $message->from_email,
                'subject' => $message->subject,
                'rfc_message_id' => $message->rfc_message_id,
                'thread_id' => $message->thread_id,
            ],
        );

        return $message->fresh();
    }
}
