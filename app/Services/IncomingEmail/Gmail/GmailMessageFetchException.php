<?php

namespace App\Services\IncomingEmail\Gmail;

use Throwable;

/**
 * Message fetch failed after retries. Callers must isolate this and continue the mailbox sync.
 */
class GmailMessageFetchException extends GmailApiException
{
    /**
     * @param  array<string, mixed>|null  $errorPayload
     */
    public function __construct(
        string $mailbox,
        string $messageId,
        int $httpStatus,
        string $endpoint,
        ?array $errorPayload = null,
        int $attemptCount = 1,
        int $elapsedMs = 0,
        ?string $requestId = null,
        ?string $historyId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Gmail message fetch failed for %s message %s: HTTP %d', $mailbox, $messageId, $httpStatus),
            $mailbox,
            $endpoint,
            $httpStatus,
            $errorPayload,
            $messageId,
            $historyId,
            $attemptCount,
            $elapsedMs,
            $requestId,
            $previous,
        );
    }
}
