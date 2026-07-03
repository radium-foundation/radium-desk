<?php

namespace App\Services\Notifications\Channels;

use App\Contracts\Notifications\NotificationChannel;
use App\Data\NotificationMessage;
use App\Data\NotificationResult;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;

class DesktopChannel implements NotificationChannel
{
    public function supports(NotificationType $type): bool
    {
        return match ($type) {
            NotificationType::RequestSerialNumber => true,
            default => false,
        };
    }

    public function send(NotificationMessage $message): NotificationResult
    {
        return NotificationResult::success(
            channel: NotificationChannelType::Desktop,
            message: 'Not Yet Configured',
            metadata: [
                'notification_type' => $message->type->value,
                'incident_id' => $message->incident->id,
                'status' => 'not_yet_configured',
            ],
        );
    }
}
