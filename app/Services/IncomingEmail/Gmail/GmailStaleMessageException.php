<?php

namespace App\Services\IncomingEmail\Gmail;

class GmailStaleMessageException extends GmailApiException
{
    public function __construct(
        string $mailbox,
        string $messageId,
        string $endpoint = '/gmail/v1/users/me/messages/{id}',
        ?string $requestId = null,
        int $elapsedMs = 0,
    ) {
        parent::__construct(
            sprintf('Gmail message not found (404) for %s: %s', $mailbox, $messageId),
            $mailbox,
            $endpoint,
            404,
            ['message' => 'Requested entity was not found.'],
            $messageId,
            null,
            1,
            $elapsedMs,
            $requestId,
        );
    }
}
