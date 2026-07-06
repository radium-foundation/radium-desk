<?php

namespace App\Services\Automation;

use App\Enums\NotificationType;

class AutomationNotificationTypeResolver
{
    public function resolve(string $actionKey): ?NotificationType
    {
        return match ($actionKey) {
            'request_serial_number',
            'request_serial_number_reminder' => NotificationType::RequestSerialNumber,
            'customer_waiting_followup' => NotificationType::CustomerWaitingFollowup,
            default => null,
        };
    }
}
