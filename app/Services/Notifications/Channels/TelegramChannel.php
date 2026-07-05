<?php

namespace App\Services\Notifications\Channels;

use App\Contracts\Notifications\NotificationChannel;
use App\Data\NotificationMessage;
use App\Data\NotificationResult;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;

/**
 * Incident notification dispatcher channel for Telegram.
 *
 * Ownership:
 * - This channel handles customer/incident notification types only
 *   (e.g. RequestSerialNumber).
 * - Ira operational Telegram (smart assignment alerts, daily briefings,
 *   operational risks) is owned by {@see \App\Services\Operations\IraCommunicationService}
 *   via {@see \App\Services\Telegram\TelegramBotService}.
 *
 * SupportAppointmentAssigned is intentionally excluded here to prevent
 * duplicate delivery alongside IraCommunicationService.
 */
class TelegramChannel implements NotificationChannel
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
            channel: NotificationChannelType::Telegram,
            message: 'Not Yet Configured',
            metadata: [
                'notification_type' => $message->type->value,
                'incident_id' => $message->incident->id,
                'status' => 'not_yet_configured',
            ],
        );
    }
}
