<?php

namespace App\Services\Telegram;

use App\Data\Telegram\TelegramSendResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    public function isConfigured(): bool
    {
        $token = config('services.telegram.bot_token');

        return is_string($token) && trim($token) !== '';
    }

    public function sendMessage(string $chatId, string $text): TelegramSendResult
    {
        if (! $this->isConfigured()) {
            return TelegramSendResult::failure('Telegram bot token is not configured.');
        }

        $token = (string) config('services.telegram.bot_token');
        $url = "https://api.telegram.org/bot{$token}/sendMessage";

        try {
            $response = Http::timeout(10)
                ->asJson()
                ->post($url, [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'disable_web_page_preview' => true,
                ]);

            if ($response->successful() && ($response->json('ok') === true)) {
                $messageId = $response->json('result.message_id');

                return TelegramSendResult::success(
                    messageId: $messageId !== null ? (string) $messageId : null,
                );
            }

            $error = $response->json('description')
                ?? $response->body()
                ?? 'Telegram API request failed.';

            Log::warning('telegram.send.failed', [
                'chat_id' => $chatId,
                'status' => $response->status(),
                'error' => $error,
            ]);

            return TelegramSendResult::failure((string) $error);
        } catch (\Throwable $exception) {
            Log::error('telegram.send.exception', [
                'chat_id' => $chatId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return TelegramSendResult::failure($exception->getMessage());
        }
    }
}
