<?php

namespace App\Services\IncomingEmail\Gmail;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Services\IncomingEmail\IncomingEmailPreviewExtractor;
use Illuminate\Support\Carbon;

class GmailMessageMapper
{
    public function __construct(
        private readonly IncomingEmailPreviewExtractor $previewExtractor,
    ) {}

    /**
     * @param  array<string, mixed>  $message
     */
    public function toNormalized(string $mailbox, array $message): NormalizedInboundEmail
    {
        $headers = $this->headerMap($message);
        $from = $this->parseAddress($headers['from'] ?? '');
        $toEmails = $this->parseAddressList($headers['to'] ?? '');
        $ccEmails = $this->parseAddressList($headers['cc'] ?? '');
        $allRecipients = array_values(array_unique([...$toEmails, ...$ccEmails]));

        if ($allRecipients === []) {
            $allRecipients = [strtolower(trim($mailbox))];
        }

        [$bodyText, $bodyHtml] = $this->extractBodies($message['payload'] ?? []);
        $attachments = $this->extractAttachments($message['payload'] ?? []);
        $preview = $this->previewExtractor->extract(
            $bodyText,
            $bodyHtml,
            is_string($message['snippet'] ?? null) ? $message['snippet'] : null,
        );

        $internalDateMs = isset($message['internalDate']) && is_numeric($message['internalDate'])
            ? (int) $message['internalDate']
            : null;

        $receivedAt = $internalDateMs !== null
            ? Carbon::createFromTimestampMs($internalDateMs, config('app.timezone'))
            : now();

        $labels = array_values(array_filter(array_map(
            static fn (mixed $label): string => trim((string) $label),
            $message['labelIds'] ?? [],
        )));

        return new NormalizedInboundEmail(
            mailbox: strtolower(trim($mailbox)),
            provider: 'gmail',
            providerMessageId: isset($message['id']) ? (string) $message['id'] : null,
            rfcMessageId: $headers['message-id'] ?? null,
            threadId: isset($message['threadId']) ? (string) $message['threadId'] : null,
            fromEmail: $from['email'] !== '' ? $from['email'] : 'unknown@invalid',
            fromName: $from['name'],
            toEmails: $allRecipients,
            subject: $headers['subject'] ?? null,
            preview: $preview,
            receivedAt: $receivedAt,
            attachmentCount: count($attachments),
            headers: $headers,
            labels: $labels,
            rawPayload: [
                'gmail_message_id' => $message['id'] ?? null,
                'gmail_thread_id' => $message['threadId'] ?? null,
                'history_id' => $message['historyId'] ?? null,
                'snippet' => $message['snippet'] ?? null,
                'size_estimate' => $message['sizeEstimate'] ?? null,
            ],
            channel: null,
            bodyText: $bodyText,
            bodyHtml: $bodyHtml,
            attachments: $attachments,
        );
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, string>
     */
    private function headerMap(array $message): array
    {
        $headers = [];

        foreach ($message['payload']['headers'] ?? [] as $header) {
            if (! is_array($header)) {
                continue;
            }

            $name = strtolower(trim((string) ($header['name'] ?? '')));
            $value = trim((string) ($header['value'] ?? ''));

            if ($name === '') {
                continue;
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * @return array{email: string, name: ?string}
     */
    private function parseAddress(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return ['email' => '', 'name' => null];
        }

        if (preg_match('/^(.*)<([^>]+)>$/', $value, $matches) === 1) {
            $name = trim($matches[1], " \t\"'");
            $email = strtolower(trim($matches[2]));

            return [
                'email' => $email,
                'name' => $name !== '' ? $name : null,
            ];
        }

        return [
            'email' => strtolower($value),
            'name' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function parseAddressList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $value) ?: [];
        $emails = [];

        foreach ($parts as $part) {
            $parsed = $this->parseAddress((string) $part);

            if ($parsed['email'] !== '' && filter_var($parsed['email'], FILTER_VALIDATE_EMAIL)) {
                $emails[] = $parsed['email'];
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: ?string, 1: ?string}
     */
    private function extractBodies(array $payload): array
    {
        $text = null;
        $html = null;
        $this->walkParts($payload, function (array $part) use (&$text, &$html): void {
            $mime = strtolower((string) ($part['mimeType'] ?? ''));
            $bodyData = $part['body']['data'] ?? null;

            if (! is_string($bodyData) || $bodyData === '') {
                return;
            }

            $decoded = $this->decodeBase64Url($bodyData);

            if ($mime === 'text/plain' && $text === null) {
                $text = $decoded;
            }

            if ($mime === 'text/html' && $html === null) {
                $html = $decoded;
            }
        });

        if ($text === null && $html === null) {
            $bodyData = $payload['body']['data'] ?? null;

            if (is_string($bodyData) && $bodyData !== '') {
                $decoded = $this->decodeBase64Url($bodyData);
                $mime = strtolower((string) ($payload['mimeType'] ?? ''));

                if ($mime === 'text/html') {
                    $html = $decoded;
                } else {
                    $text = $decoded;
                }
            }
        }

        return [$text, $html];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{filename: ?string, mime_type: ?string, size: ?int, attachment_id: ?string}>
     */
    private function extractAttachments(array $payload): array
    {
        $attachments = [];

        $this->walkParts($payload, function (array $part) use (&$attachments): void {
            $filename = isset($part['filename']) ? trim((string) $part['filename']) : '';
            $attachmentId = $part['body']['attachmentId'] ?? null;

            if ($filename === '' && ! is_string($attachmentId)) {
                return;
            }

            if ($filename === '' && is_string($attachmentId)) {
                $filename = 'attachment';
            }

            $attachments[] = [
                'filename' => $filename !== '' ? $filename : null,
                'mime_type' => isset($part['mimeType']) ? (string) $part['mimeType'] : null,
                'size' => isset($part['body']['size']) && is_numeric($part['body']['size'])
                    ? (int) $part['body']['size']
                    : null,
                'attachment_id' => is_string($attachmentId) ? $attachmentId : null,
            ];
        });

        return $attachments;
    }

    /**
     * @param  array<string, mixed>  $part
     * @param  callable(array<string, mixed>): void  $callback
     */
    private function walkParts(array $part, callable $callback): void
    {
        $callback($part);

        foreach ($part['parts'] ?? [] as $child) {
            if (is_array($child)) {
                $this->walkParts($child, $callback);
            }
        }
    }

    private function decodeBase64Url(string $value): string
    {
        $remainder = strlen($value) % 4;

        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : '';
    }
}
