<?php

namespace App\Services\Operations;

use App\Enums\IraNotificationChannel;
use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use App\Models\IraNotification;
use App\Models\User;

class IraNotificationService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(
        User $user,
        IraNotificationType $type,
        string $title,
        string $message,
        array $payload = [],
        IraNotificationChannel $channel = IraNotificationChannel::Telegram,
    ): IraNotification {
        return IraNotification::query()->create([
            'user_id' => $user->id,
            'notification_type' => $type,
            'channel' => $channel,
            'title' => $title,
            'message' => $message,
            'payload' => $payload,
            'status' => IraNotificationStatus::Pending,
        ]);
    }

    public function markSent(IraNotification $notification): IraNotification
    {
        $notification->update([
            'status' => IraNotificationStatus::Sent,
            'sent_at' => now(),
            'error_message' => null,
        ]);

        return $notification->refresh();
    }

    public function markFailed(IraNotification $notification, string $error): IraNotification
    {
        $notification->update([
            'status' => IraNotificationStatus::Failed,
            'error_message' => $error,
        ]);

        return $notification->refresh();
    }

    public function markSkipped(IraNotification $notification, string $reason): IraNotification
    {
        $notification->update([
            'status' => IraNotificationStatus::Skipped,
            'error_message' => $reason,
        ]);

        return $notification->refresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentForDashboard(int $limit = 15): array
    {
        return IraNotification::query()
            ->with('user:id,name,first_name')
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (IraNotification $notification): array => [
                'id' => $notification->id,
                'timestamp' => $notification->created_at,
                'sent_at' => $notification->sent_at,
                'recipient' => $notification->user?->name ?? 'Unknown',
                'type' => $notification->notification_type->label(),
                'channel' => $notification->channel->label(),
                'title' => $notification->title,
                'status' => $notification->status->value,
                'status_label' => $notification->status->label(),
                'error_message' => $notification->error_message,
            ])
            ->all();
    }
}
