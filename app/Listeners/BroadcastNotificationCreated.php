<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\IncomingCallAssistNotification;
use App\Services\DashboardBroadcastService;
use Illuminate\Notifications\Events\NotificationSent;

class BroadcastNotificationCreated
{
    public function __construct(
        private readonly DashboardBroadcastService $dashboardBroadcastService,
    ) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof User) {
            return;
        }

        $notification = $event->notifiable->notifications()->latest()->first();

        if ($notification === null) {
            return;
        }

        $suppressDesktop = config('operator_alerts.enabled')
            && $notification->type === IncomingCallAssistNotification::class;

        $this->dashboardBroadcastService->notificationCreated(
            recipient: $event->notifiable,
            notification: $notification,
            suppressDesktopNotification: $suppressDesktop,
        );
    }
}
