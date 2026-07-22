<?php

namespace Tests\Unit\Telegram;

use App\Services\Telegram\TelegramBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramBotServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.bot_token' => 'test-bot-token']);
    }

    public function test_send_message_is_skipped_when_system_setting_is_disabled(): void
    {
        Http::fake();

        $this->disableTelegramNotifications();

        $result = app(TelegramBotService::class)->sendMessage('123456789', 'Test message');

        $this->assertFalse($result->success);
        $this->assertTrue($result->skipped);
        $this->assertSame(
            TelegramBotService::DISABLED_BY_SYSTEM_SETTINGS,
            $result->error,
        );
        Http::assertNothingSent();
    }

    public function test_send_message_reaches_telegram_api_when_system_setting_is_enabled(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 99],
            ], 200),
        ]);

        $this->enableTelegramNotifications();

        $result = app(TelegramBotService::class)->sendMessage('123456789', 'Test message');

        $this->assertTrue($result->success);
        $this->assertFalse($result->skipped);
        Http::assertSentCount(1);
    }
}
