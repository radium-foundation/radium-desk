<?php

namespace App\Services\ConversationWorkspace;

use App\Models\Incident;
use App\Models\Order;

/**
 * Gates the Customer Conversation Workspace top section inside Customer360.
 */
class ConversationWorkspaceModeResolver
{
    /**
     * @param  array{live_incoming_call?: bool}  $context
     */
    public function isActive(Incident $incident, ?Order $order, array $context = []): bool
    {
        if (! config('conversation_workspace.enabled')) {
            return false;
        }

        if ($order === null || ! $order->isInquiryOrder()) {
            return false;
        }

        if ($incident->inquiry_origin_order_id !== null) {
            return false;
        }

        return (bool) ($context['live_incoming_call'] ?? false);
    }
}
