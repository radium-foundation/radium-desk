<?php

namespace App\Services\IncomingEmail\Gmail;

use RuntimeException;

class GmailStaleMessageException extends RuntimeException
{
    public function __construct(
        public readonly string $mailbox,
        public readonly string $messageId,
    ) {
        parent::__construct(
            sprintf('Gmail message not found (404) for %s: %s', $mailbox, $messageId),
            404,
        );
    }
}
