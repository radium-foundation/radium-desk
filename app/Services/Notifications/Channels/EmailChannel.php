<?php

namespace App\Services\Notifications\Channels;

use App\Contracts\Notifications\NotificationChannel;
use App\Data\NotificationMessage;
use App\Data\NotificationResult;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;

class EmailChannel implements NotificationChannel
{
    public function supports(NotificationType $type): bool
    {
        return match ($type) {
            NotificationType::RequestSerialNumber => true,
        };
    }

    public function send(NotificationMessage $message): NotificationResult
    {
        return NotificationResult::failure(
            channel: NotificationChannelType::Email,
            message: 'Email notifications are not implemented yet.',
            retryable: false,
            metadata: [
                'status' => 'not_implemented',
                'notification_type' => $message->type->value,
            ],
        );
    }
}
