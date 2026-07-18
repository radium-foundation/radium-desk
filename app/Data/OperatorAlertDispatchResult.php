<?php

namespace App\Data;

readonly class OperatorAlertDispatchResult
{
    /**
     * @param  list<int>  $recipientIds
     * @param  list<int>  $telegramRecipientIds
     */
    public function __construct(
        public bool $dispatched,
        public array $recipientIds = [],
        public bool $historyPersisted = false,
        public array $telegramRecipientIds = [],
        public ?string $reason = null,
    ) {}

    public static function disabled(): self
    {
        return new self(
            dispatched: false,
            reason: 'operator_alerts_disabled',
        );
    }

    public static function noRecipients(): self
    {
        return new self(
            dispatched: false,
            reason: 'no_recipients',
        );
    }

    public static function duplicate(string $deduplicationKey): self
    {
        return new self(
            dispatched: false,
            reason: 'duplicate:'.$deduplicationKey,
        );
    }

    public function telegramSent(): bool
    {
        return $this->telegramRecipientIds !== [];
    }
}
