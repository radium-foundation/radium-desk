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

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return ! array_key_exists('parse_mode', $payload)
                && ($payload['disable_web_page_preview'] ?? null) === true
                && ! array_key_exists('entities', $payload);
        });
    }

    public function test_send_message_includes_entities_only_when_supplied(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 101],
            ], 200),
        ]);

        $this->enableTelegramNotifications();

        $entities = [[
            'type' => 'text_link',
            'offset' => 6,
            'length' => 7,
            'url' => 'https://desk.example.com/incidents/123',
        ]];

        app(TelegramBotService::class)->sendMessage('123456789', 'Case: SC40157', $entities);

        Http::assertSent(function ($request) use ($entities): bool {
            $payload = $request->data();

            return ($payload['text'] ?? null) === 'Case: SC40157'
                && ($payload['entities'] ?? null) === $entities
                && ! array_key_exists('parse_mode', $payload)
                && ($payload['disable_web_page_preview'] ?? null) === true;
        });
    }

    public function test_send_message_payload_remains_plain_text_compatible_with_bare_urls(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 100],
            ], 200),
        ]);

        $this->enableTelegramNotifications();

        $message = implode("\n", [
            'Case: SC40157',
            'Open Case: https://desk.example.com/incidents/SC40157',
        ]);

        app(TelegramBotService::class)->sendMessage('123456789', $message);

        Http::assertSent(function ($request) use ($message): bool {
            $payload = $request->data();

            return ($payload['text'] ?? null) === $message
                && ! array_key_exists('parse_mode', $payload);
        });
    }
}
