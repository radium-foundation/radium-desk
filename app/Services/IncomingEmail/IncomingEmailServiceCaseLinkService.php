<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncomingEmailMessageStatus;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\User;
use RuntimeException;

class IncomingEmailServiceCaseLinkService
{
    public function __construct(
        private readonly IncomingEmailLinkService $linkService,
    ) {}

    public function linkHistoricalMessageIfPresent(
        Order $order,
        Incident $incident,
        User $actor,
        ?int $incomingEmailMessageId,
    ): void {
        if ($incomingEmailMessageId === null || $incomingEmailMessageId <= 0) {
            return;
        }

        $message = IncomingEmailMessage::query()->find($incomingEmailMessageId);

        if ($message === null) {
            throw new RuntimeException('Incoming email message not found: '.$incomingEmailMessageId);
        }

        if ($message->incident_id !== null) {
            if ((int) $message->incident_id === (int) $incident->id) {
                return;
            }

            throw new RuntimeException('Incoming email message is already linked to a different service case.');
        }

        if ($message->status !== IncomingEmailMessageStatus::HistoricalCustomer) {
            throw new RuntimeException('Incoming email message is not eligible for service case linking.');
        }

        if ((int) $message->order_id !== (int) $order->id) {
            throw new RuntimeException('Incoming email message does not belong to this order.');
        }

        $this->linkService->promoteToServiceCase($incident, $message, $actor);
    }
}
