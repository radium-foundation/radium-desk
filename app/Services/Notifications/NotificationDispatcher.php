<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\NotificationChannel;
use App\Data\NotificationDispatchResult;
use App\Data\NotificationMessage;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use App\Services\Notifications\Channels\DesktopChannel;
use App\Services\Notifications\Channels\EmailChannel;
use App\Services\Notifications\Channels\TelegramChannel;
use App\Services\Notifications\Channels\WhatsAppChannel;
use App\Services\SystemSettingsService;

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
    ) {}

    public function send(NotificationType $type, NotificationMessage $message): NotificationDispatchResult
    {
        $results = [];

        foreach ($this->resolveEnabledChannels($type) as $channel) {
            $results[] = $channel->send($message);
        }

        return NotificationDispatchResult::fromResults($results);
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
