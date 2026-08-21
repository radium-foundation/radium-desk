<?php

namespace App\Data\Telegram;

readonly class TelegramOutboundMessage
{
    /**
     * @param  list<array{type: string, offset: int, length: int, url?: string}>|null  $entities
     */
    public function __construct(
        public string $text,
        public ?array $entities = null,
    ) {}
}
