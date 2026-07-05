<?php

namespace App\Data\Telegram;

readonly class TelegramSendResult
{
    public function __construct(
        public bool $success,
        public ?string $messageId = null,
        public ?string $error = null,
    ) {}

    public static function success(?string $messageId = null): self
    {
        return new self(success: true, messageId: $messageId);
    }

    public static function failure(string $error): self
    {
        return new self(success: false, error: $error);
    }
}
