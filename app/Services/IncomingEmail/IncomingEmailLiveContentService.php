<?php

namespace App\Services\IncomingEmail;

use App\Models\IncomingEmailMessage;
use App\Services\IncomingEmail\Gmail\GmailApiClient;
use App\Services\IncomingEmail\Gmail\GmailMessageMapper;
use RuntimeException;

class IncomingEmailLiveContentService
{
    public function __construct(
        private readonly GmailApiClient $gmailApiClient,
        private readonly GmailMessageMapper $gmailMessageMapper,
    ) {}

    /**
     * @return array{
     *     subject: ?string,
     *     from_email: string,
     *     from_name: ?string,
     *     received_at: ?string,
     *     body_text: ?string,
     *     body_html: ?string,
     *     source: string,
     *     attachments: list<array{attachment_id: ?string, filename: ?string, mime_type: ?string, size: ?int}>,
     * }
     */
    public function resolve(IncomingEmailMessage $message): array
    {
        if ($message->hasLegacyStoredBody()) {
            return [
                'subject' => $message->subject,
                'from_email' => $message->from_email,
                'from_name' => $message->from_name,
                'received_at' => $message->received_at?->toIso8601String(),
                'body_text' => $message->legacyBodyText(),
                'body_html' => $message->legacyBodyHtml(),
                'source' => 'database',
                'attachments' => $message->attachmentMetadata(),
            ];
        }

        if ($message->provider !== 'gmail' || blank($message->provider_message_id)) {
            throw new RuntimeException('Full email content is only available for Gmail messages.');
        }

        $raw = $this->gmailApiClient->getMessage($message->mailbox, (string) $message->provider_message_id);
        $normalized = $this->gmailMessageMapper->toNormalized($message->mailbox, $raw);

        return [
            'subject' => $normalized->subject ?? $message->subject,
            'from_email' => $normalized->fromEmail,
            'from_name' => $normalized->fromName,
            'received_at' => $message->received_at?->toIso8601String(),
            'body_text' => $normalized->bodyText,
            'body_html' => $normalized->bodyHtml,
            'source' => 'gmail',
            'attachments' => $normalized->attachments,
        ];
    }

    public function downloadAttachment(
        IncomingEmailMessage $message,
        string $attachmentId,
    ): array {
        $metadata = collect($message->attachmentMetadata())
            ->first(fn (array $attachment): bool => ($attachment['attachment_id'] ?? null) === $attachmentId);

        if ($metadata === null) {
            throw new RuntimeException('Attachment metadata not found for this message.');
        }

        if ($message->provider !== 'gmail' || blank($message->provider_message_id)) {
            throw new RuntimeException('Attachment download is only available for Gmail messages.');
        }

        $binary = $this->gmailApiClient->getAttachmentBinary(
            $message->mailbox,
            (string) $message->provider_message_id,
            $attachmentId,
        );

        return [
            'binary' => $binary,
            'filename' => (string) ($metadata['filename'] ?? 'attachment'),
            'mime_type' => (string) ($metadata['mime_type'] ?? 'application/octet-stream'),
        ];
    }
}
