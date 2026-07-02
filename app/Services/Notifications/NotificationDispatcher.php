<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\NotificationChannel;
use App\Data\NotificationDispatchResult;
use App\Data\NotificationMessage;
use App\Data\NotificationResult;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use App\Services\Notifications\Channels\DesktopChannel;
use App\Services\Notifications\Channels\EmailChannel;
use App\Services\Notifications\Channels\TelegramChannel;
use App\Services\Notifications\Channels\WhatsAppChannel;
use App\Services\SystemSettingsService;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationDispatcher
{
    /**
     * @var array<string, string>
     */
    private const CHANNEL_SETTING_KEYS = [
        WhatsAppChannel::class => 'notifications.whatsapp.enabled',
        EmailChannel::class => 'notifications.email.enabled',
        DesktopChannel::class => 'notifications.desktop.enabled',
        TelegramChannel::class => 'notifications.telegram.enabled',
    ];

    /**
     * @param  array<int, NotificationChannel>  $channels
     */
    public function __construct(
        private readonly SystemSettingsService $systemSettings,
        private readonly array $channels,
        private readonly NotificationAuditTrailService $auditTrail,
    ) {}

    public function send(NotificationType $type, NotificationMessage $message): NotificationDispatchResult
    {
        $startedAt = microtime(true);
        $enabledChannels = $this->resolveEnabledChannels($type);

        Log::info('notification.dispatch.started', [
            'notification_type' => $type->value,
            'incident_id' => $message->incident->id,
            'channel_count' => count($enabledChannels),
            'channels' => array_map(
                fn (NotificationChannel $channel): ?string => $this->channelTypeFor($channel)?->value,
                $enabledChannels,
            ),
        ]);

        $results = [];
        $channelRecords = [];

        foreach ($enabledChannels as $channel) {
            $channelType = $this->channelTypeFor($channel);

            if ($channelType === null) {
                continue;
            }

            Log::info('notification.dispatch.channel.started', [
                'notification_type' => $type->value,
                'incident_id' => $message->incident->id,
                'channel' => $channelType->value,
            ]);

            $channelStartedAt = microtime(true);

            try {
                $result = $channel->send($message);
            } catch (Throwable $exception) {
                Log::error('notification.dispatch.channel.exception', [
                    'notification_type' => $type->value,
                    'incident_id' => $message->incident->id,
                    'channel' => $channelType->value,
                    'exception' => $exception->getMessage(),
                ]);

                $result = NotificationResult::failure(
                    channel: $channelType,
                    message: 'Unexpected channel failure: '.$exception->getMessage(),
                    retryable: true,
                    metadata: [
                        'status' => 'exception',
                        'notification_type' => $type->value,
                        'incident_id' => $message->incident->id,
                    ],
                );
            }

            $durationMs = (int) round((microtime(true) - $channelStartedAt) * 1000);
            $completedAt = now()->toIso8601String();

            Log::info('notification.dispatch.channel.completed', [
                'notification_type' => $type->value,
                'incident_id' => $message->incident->id,
                'channel' => $channelType->value,
                'success' => $result->success,
                'status' => $result->status(),
                'retryable' => $result->retryable,
                'duration_ms' => $durationMs,
                'message' => $result->message,
            ]);

            $results[] = $result;
            $channelRecords[] = $result->toAuditRecord($completedAt, $durationMs);
        }

        $dispatchResult = NotificationDispatchResult::fromResults($results);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::info('notification.dispatch.completed', [
            'notification_type' => $type->value,
            'incident_id' => $message->incident->id,
            'success' => $dispatchResult->success,
            'duration_ms' => $durationMs,
            'channel_count' => count($results),
            'delivered_count' => count(array_filter(
                $results,
                fn (NotificationResult $result): bool => $result->countsTowardSuccess(),
            )),
            'message' => $dispatchResult->message,
        ]);

        if ($channelRecords !== []) {
            $this->auditTrail->record($message, $dispatchResult, $channelRecords);
        }

        return $dispatchResult;
    }

    /**
     * @return array<int, NotificationChannel>
     */
    public function resolveEnabledChannels(NotificationType $type): array
    {
        return array_values(array_filter(
            $this->channels,
            fn (NotificationChannel $channel): bool => $channel->supports($type)
                && $this->isChannelEnabled($channel),
        ));
    }

    /**
     * @return array<int, NotificationChannel>
     */
    public function channels(): array
    {
        return $this->channels;
    }

    public function channelTypeFor(NotificationChannel $channel): ?NotificationChannelType
    {
        return match ($channel::class) {
            WhatsAppChannel::class => NotificationChannelType::WhatsApp,
            EmailChannel::class => NotificationChannelType::Email,
            DesktopChannel::class => NotificationChannelType::Desktop,
            TelegramChannel::class => NotificationChannelType::Telegram,
            default => null,
        };
    }

    private function isChannelEnabled(NotificationChannel $channel): bool
    {
        $settingKey = self::CHANNEL_SETTING_KEYS[$channel::class] ?? null;

        if ($settingKey === null) {
            return false;
        }

        return (bool) $this->systemSettings->get($settingKey);
    }
}
