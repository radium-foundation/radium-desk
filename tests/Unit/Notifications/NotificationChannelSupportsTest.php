<?php

namespace Tests\Unit\Notifications;

use App\Contracts\Notifications\NotificationChannel;
use App\Enums\NotificationType;
use App\Services\Notifications\Channels\DesktopChannel;
use App\Services\Notifications\Channels\EmailChannel;
use App\Services\Notifications\Channels\TelegramChannel;
use App\Services\Notifications\Channels\WhatsAppChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationChannelSupportsTest extends TestCase
{
    /**
     * @return list<array{0: class-string<NotificationChannel>}>
     */
    public static function channelProvider(): array
    {
        return [
            [WhatsAppChannel::class],
            [EmailChannel::class],
            [TelegramChannel::class],
            [DesktopChannel::class],
        ];
    }

    /**
     * @param  class-string<NotificationChannel>  $channelClass
     */
    #[DataProvider('channelProvider')]
    public function test_support_appointment_assigned_does_not_throw_and_returns_false(string $channelClass): void
    {
        $channel = app($channelClass);

        $this->assertFalse($channel->supports(NotificationType::SupportAppointmentAssigned));
    }

    /**
     * @param  class-string<NotificationChannel>  $channelClass
     */
    #[DataProvider('channelProvider')]
    public function test_supports_returns_bool_for_every_notification_type(string $channelClass): void
    {
        $channel = app($channelClass);

        foreach (NotificationType::cases() as $type) {
            $this->assertIsBool(
                $channel->supports($type),
                sprintf('%s::supports(%s) must return bool', $channelClass, $type->name),
            );
        }
    }

    public function test_whatsapp_and_email_do_not_enable_support_appointment_assigned(): void
    {
        $this->assertFalse(app(WhatsAppChannel::class)->supports(NotificationType::SupportAppointmentAssigned));
        $this->assertFalse(app(EmailChannel::class)->supports(NotificationType::SupportAppointmentAssigned));
    }

    public function test_request_serial_number_remains_supported_where_expected(): void
    {
        $this->assertTrue(app(WhatsAppChannel::class)->supports(NotificationType::RequestSerialNumber));
        $this->assertTrue(app(EmailChannel::class)->supports(NotificationType::RequestSerialNumber));
        $this->assertTrue(app(TelegramChannel::class)->supports(NotificationType::RequestSerialNumber));
        $this->assertTrue(app(DesktopChannel::class)->supports(NotificationType::RequestSerialNumber));
    }
}
