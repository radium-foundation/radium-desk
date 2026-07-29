<?php

namespace App\Services\IncomingEmail\Gmail;

use RuntimeException;
use Throwable;

class GmailApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $errorPayload
     */
    public function __construct(
        string $message,
        public readonly string $mailbox,
        public readonly string $endpoint,
        public readonly int $httpStatus,
        public readonly ?array $errorPayload = null,
        public readonly ?string $messageId = null,
        public readonly ?string $historyId = null,
        public readonly int $attemptCount = 1,
        public readonly int $elapsedMs = 0,
        public readonly ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'mailbox' => $this->mailbox,
            'endpoint' => $this->endpoint,
            'http_status' => $this->httpStatus,
            'google_error' => $this->errorPayload,
            'message_id' => $this->messageId,
            'history_id' => $this->historyId,
            'attempt_count' => $this->attemptCount,
            'elapsed_ms' => $this->elapsedMs,
            'request_id' => $this->requestId,
        ];
    }
}
