<?php

namespace App\Data\IncomingEmail;

use Illuminate\Support\Carbon;

readonly class NormalizedInboundEmail
{
    /**
     * @param  list<string>  $toEmails
     * @param  array<string, mixed>  $headers
     * @param  list<string>  $labels
     * @param  array<string, mixed>|null  $rawPayload
     * @param  list<array{filename: ?string, mime_type: ?string, size: ?int, attachment_id: ?string}>  $attachments
     */
    public function __construct(
        public string $mailbox,
        public string $provider,
        public ?string $providerMessageId,
        public ?string $rfcMessageId,
        public ?string $threadId,
        public string $fromEmail,
        public ?string $fromName,
        public array $toEmails,
        public ?string $subject,
        public ?string $preview,
        public Carbon $receivedAt,
        public int $attachmentCount = 0,
        public array $headers = [],
        public array $labels = [],
        public ?array $rawPayload = null,
        public ?string $channel = null,
        public ?string $bodyText = null,
        public ?string $bodyHtml = null,
        public array $attachments = [],
    ) {}
}
