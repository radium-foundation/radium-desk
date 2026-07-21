<?php

namespace App\Services\HybridRealtime;

use App\Data\OperatorAlert;
use App\Data\RealtimeNotification;
use App\Enums\NotificationPriority;
use App\Events\Dashboard\IncomingCallReceived;
use App\Events\Dashboard\RealtimeNotificationDelivered;
use App\Models\BonvoiceCallAlert;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HybridRealtimeNotificationBroadcaster
{
    public function __construct(
        private readonly HybridRealtimeFeatureService $hybridRealtime,
        private readonly HybridRealtimeNotificationDeliveryService $delivery,
    ) {}

    public function incomingCallsEnabled(): bool
    {
        return $this->hybridRealtime->enabled(HybridRealtimeFeature::INCOMING_CALLS);
    }

    public function desktopNotificationsEnabled(): bool
    {
        return $this->hybridRealtime->enabled(HybridRealtimeFeature::DESKTOP_NOTIFICATIONS);
    }

    public function operatorAlertsEnabled(): bool
    {
        return $this->hybridRealtime->enabled(HybridRealtimeFeature::OPERATOR_ALERTS);
    }

    public function broadcastIncomingCall(User $recipient, BonvoiceCallAlert $alert): void
    {
        if (! $this->incomingCallsEnabled()) {
            return;
        }

        $alert->loadMissing(['user', 'order', 'incident']);

        $call = [
            'call_id' => (string) $alert->call_id,
            'customer_name' => $alert->order?->customer_name,
            'mobile_number' => $alert->customer_phone,
            'call_status' => 'ringing',
            'assigned_operator' => $alert->user?->name,
            'received_at' => $alert->notified_at?->toIso8601String() ?? now()->toIso8601String(),
            'incident_id' => $alert->incident_id,
            'action_url' => $alert->incident_id
                ? route('dashboard', ['open_customer_360' => $alert->incident_id])
                : route('dashboard'),
        ];

        DB::afterCommit(function () use ($recipient, $call): void {
            $freshRecipient = User::query()->find($recipient->id);

            if ($freshRecipient === null) {
                return;
            }

            broadcast(new IncomingCallReceived($freshRecipient, $call));
        });
    }

    public function broadcastOperatorAlert(User $recipient, OperatorAlert $alert): void
    {
        if (! $this->operatorAlertsEnabled()) {
            return;
        }

        $priority = NotificationPriority::fromAlertSeverity($alert->severity);
        $notification = $this->buildFromOperatorAlert($alert, $priority);

        $this->broadcastRealtimeNotification($recipient, $notification);
    }

    public function broadcastRealtimeNotification(User $recipient, RealtimeNotification $notification): void
    {
        if (! $this->desktopNotificationsEnabled() && $notification->type !== 'operator_alert') {
            return;
        }

        if ($notification->type === 'operator_alert' && ! $this->operatorAlertsEnabled()) {
            return;
        }

        DB::afterCommit(function () use ($recipient, $notification): void {
            $freshRecipient = User::query()->find($recipient->id);

            if ($freshRecipient === null) {
                return;
            }

            broadcast(new RealtimeNotificationDelivered($freshRecipient, $notification));
        });
    }

    public function buildFromOperatorAlert(OperatorAlert $alert, ?NotificationPriority $priority = null): RealtimeNotification
    {
        $priority ??= NotificationPriority::fromAlertSeverity($alert->severity);

        return new RealtimeNotification(
            id: $alert->deduplicationKey !== '' ? $alert->deduplicationKey : uniqid('alert_', true),
            type: 'operator_alert',
            title: $alert->title,
            message: $alert->message,
            priority: $priority,
            icon: $alert->icon,
            actionUrl: $alert->actionUrl,
            deduplicationKey: $alert->deduplicationKey,
            interaction: $alert->interaction,
            playSound: $this->delivery->shouldPlaySound($priority, $alert->playSound),
            browserNotification: $this->delivery->shouldShowBrowser($priority, $alert->desktopPopup),
            showToast: $this->delivery->shouldShowToast($priority, true),
            toastDurationMs: $this->delivery->toastDurationMs(),
            requiresAcknowledgement: $priority === NotificationPriority::Critical,
            actions: $alert->actionUrl !== '' ? [['label' => 'View', 'url' => $alert->actionUrl]] : [],
        );
    }
}
