<?php

namespace App\Services\Alerts;

use App\Data\OperatorAlert;
use App\Data\OperatorAlertDispatchResult;
use App\Enums\NotificationChannelType;
use App\Events\Dashboard\OperatorAlertRaised;
use App\Models\User;
use App\Services\HybridRealtime\HybridRealtimeNotificationBroadcaster;
use App\Services\HybridRealtime\HybridRealtimeNotificationDeliveryService;
use App\Services\Notifications\NotificationAuthorityService;
use App\Services\Notifications\NotificationRecipientResolver;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class OperatorAlertDispatcher
{
    private const DEDUPE_TTL_SECONDS = 21600;

    public function __construct(
        private readonly NotificationRecipientResolver $recipientResolver,
        private readonly NotificationAuthorityService $notificationAuthority,
        private readonly TelegramBotService $telegramBot,
        private readonly HybridRealtimeNotificationBroadcaster $hybridRealtimeBroadcaster,
    ) {}

    /**
     * Central entry point for operator-facing live alerts.
     *
     * Feature-flagged. History uses existing Laravel Notifications.
     * Telegram is optional, personal, authority-gated, and best-effort.
     *
     * @param  User|iterable<int, User>|null  $recipients
     * @param  array<string, mixed>  $recipientContext
     */
    public function dispatch(
        OperatorAlert $alert,
        User|iterable|null $recipients = null,
        array $recipientContext = [],
        ?Notification $historyNotification = null,
        bool $persistHistory = false,
        bool $deliverTelegram = false,
        ?string $telegramMessage = null,
    ): OperatorAlertDispatchResult {
        if (! $this->hybridRealtimeBroadcaster->operatorAlertsEnabled() && ! config('operator_alerts.enabled')) {
            return OperatorAlertDispatchResult::disabled();
        }

        $resolvedRecipients = $this->resolveRecipients($alert, $recipients, $recipientContext);

        if ($resolvedRecipients->isEmpty()) {
            return OperatorAlertDispatchResult::noRecipients();
        }

        if (! $this->claimDeduplicationKey($alert->deduplicationKey)) {
            return OperatorAlertDispatchResult::duplicate($alert->deduplicationKey);
        }

        $historyPersisted = false;

        if ($persistHistory && $historyNotification !== null) {
            NotificationFacade::send($resolvedRecipients, $historyNotification);
            $historyPersisted = true;
        }

        foreach ($resolvedRecipients as $recipient) {
            $this->broadcastLiveAlert($recipient, $alert);
        }

        $telegramRecipientIds = [];

        if ($deliverTelegram && filled($telegramMessage)) {
            foreach ($resolvedRecipients as $recipient) {
                if ($this->deliverTelegram($recipient, $alert, $telegramMessage)) {
                    $telegramRecipientIds[] = $recipient->id;
                }
            }
        }

        return new OperatorAlertDispatchResult(
            dispatched: true,
            recipientIds: $resolvedRecipients->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            historyPersisted: $historyPersisted,
            telegramRecipientIds: $telegramRecipientIds,
        );
    }

    /**
     * @param  User|iterable<int, User>|null  $recipients
     * @param  array<string, mixed>  $recipientContext
     * @return Collection<int, User>
     */
    private function resolveRecipients(
        OperatorAlert $alert,
        User|iterable|null $recipients,
        array $recipientContext,
    ): Collection {
        if ($recipients instanceof User) {
            $resolved = collect([$recipients]);
        } elseif ($recipients === null) {
            $resolved = $this->recipientResolver->recipientsFor($alert->category, $recipientContext);
        } else {
            $resolved = collect($recipients);
        }

        return $resolved
            ->filter(fn (mixed $recipient): bool => $recipient instanceof User)
            ->filter(fn (User $recipient): bool => $recipient->is_active && ! $recipient->trashed())
            ->unique(fn (User $recipient): int => $recipient->id)
            ->values();
    }

    private function claimDeduplicationKey(string $deduplicationKey): bool
    {
        if ($deduplicationKey === '') {
            return true;
        }

        return Cache::add(
            $this->dedupeCacheKey($deduplicationKey),
            true,
            self::DEDUPE_TTL_SECONDS,
        );
    }

    private function dedupeCacheKey(string $deduplicationKey): string
    {
        return 'operator_alert:dispatch:'.$deduplicationKey;
    }

    private function broadcastLiveAlert(User $recipient, OperatorAlert $alert): void
    {
        $payloadAlert = $this->applyDeliveryFlags($alert);

        broadcast(new OperatorAlertRaised(
            recipient: $recipient,
            alert: $payloadAlert,
        ));

        $this->hybridRealtimeBroadcaster->broadcastOperatorAlert($recipient, $payloadAlert);
    }

    private function deliverTelegram(User $recipient, OperatorAlert $alert, string $telegramMessage): bool
    {
        if (! $this->notificationAuthority->shouldDeliver(
            $recipient,
            $alert->category,
            NotificationChannelType::Telegram,
        )) {
            return false;
        }

        if (! $this->telegramBot->isConfigured()) {
            return false;
        }

        $result = $this->telegramBot->sendMessage(
            chatId: (string) $recipient->telegram_chat_id,
            text: $telegramMessage,
        );

        if (! $result->success) {
            Log::warning('operator_alert.telegram_failed', [
                'user_id' => $recipient->id,
                'deduplication_key' => $alert->deduplicationKey,
                'error' => $result->error,
            ]);
        }

        return $result->success;
    }

    private function applyDeliveryFlags(OperatorAlert $alert): OperatorAlert
    {
        $delivery = app(HybridRealtimeNotificationDeliveryService::class);
        $desktopEnabled = $delivery->browserNotificationsEnabled();
        $soundEnabled = $delivery->soundEnabled();

        if ($desktopEnabled && $soundEnabled) {
            return $alert;
        }

        return new OperatorAlert(
            title: $alert->title,
            message: $alert->message,
            severity: $alert->severity,
            category: $alert->category,
            icon: $alert->icon,
            actionUrl: $alert->actionUrl,
            entityType: $alert->entityType,
            entityId: $alert->entityId,
            deduplicationKey: $alert->deduplicationKey,
            interaction: $alert->interaction,
            desktopPopup: $desktopEnabled && $alert->desktopPopup,
            playSound: $soundEnabled && $alert->playSound,
        );
    }
}
