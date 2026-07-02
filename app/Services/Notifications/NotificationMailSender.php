<?php

namespace App\Services\Notifications;

use App\Mail\NotificationMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationMailSender
{
    public function isEnabled(): bool
    {
        return (bool) config('mail.enabled', true);
    }

    /**
     * @return array{success: bool, message_id: ?string, error: ?string}
     */
    public function send(string $recipientEmail, NotificationMail $mail): array
    {
        try {
            $sentMessage = Mail::to($recipientEmail)->send($mail);

            return [
                'success' => true,
                'message_id' => $sentMessage?->getMessageId(),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            Log::error('notification.email.transport_failed', [
                'recipient_email' => $recipientEmail,
                'mailer' => config('mail.default'),
                'error' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
