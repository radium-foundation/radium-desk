<?php

namespace App\Data;

use App\Enums\WhatsAppConversationStatus;
use Illuminate\Support\Carbon;

readonly class WhatsAppConversationSnapshot
{
    public function __construct(
        public string $customerPhone,
        public int $messagesExchangedCount,
        public WhatsAppConversationStatus $conversationStatus,
        public string $lastSender,
        public ?string $lastTemplateName,
        public ?string $lastMessageId,
        public Carbon $lastActivityAt,
        public ?string $interaktCustomerId = null,
        public ?string $conversationId = null,
    ) {}
}
