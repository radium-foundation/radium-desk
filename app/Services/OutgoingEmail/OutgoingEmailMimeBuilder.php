<?php

namespace App\Services\OutgoingEmail;

class OutgoingEmailMimeBuilder
{
    /**
     * Build a base64url-encoded RFC 822 message for Gmail users.messages.send.
     */
    public function buildRawBase64Url(
        string $fromEmail,
        string $toEmail,
        string $subject,
        string $bodyHtml,
        ?string $inReplyToMessageId = null,
        ?string $references = null,
    ): string {
        $boundary = 'radium_'.bin2hex(random_bytes(12));
        $messageId = sprintf('<%s@radium-desk.local>', bin2hex(random_bytes(16)));
        $encodedSubject = $this->encodeHeader($subject);
        $plain = trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($plain === '') {
            $plain = '(no content)';
        }

        $headers = [
            'MIME-Version: 1.0',
            'From: '.$this->sanitizeAddress($fromEmail),
            'To: '.$this->sanitizeAddress($toEmail),
            'Subject: '.$encodedSubject,
            'Message-ID: '.$messageId,
            'Date: '.gmdate('D, d M Y H:i:s O'),
            'Content-Type: multipart/alternative; boundary="'.$boundary.'"',
        ];

        if ($inReplyToMessageId !== null && trim($inReplyToMessageId) !== '') {
            $normalized = $this->normalizeMessageId($inReplyToMessageId);
            $headers[] = 'In-Reply-To: '.$normalized;
            $headers[] = 'References: '.($references !== null && trim($references) !== ''
                ? $this->normalizeMessageId($references)
                : $normalized);
        }

        $body = [
            '--'.$boundary,
            'Content-Type: text/plain; charset="UTF-8"',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            $this->quotedPrintable($plain),
            '--'.$boundary,
            'Content-Type: text/html; charset="UTF-8"',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            $this->quotedPrintable($bodyHtml),
            '--'.$boundary.'--',
            '',
        ];

        $raw = implode("\r\n", $headers)."\r\n\r\n".implode("\r\n", $body);

        return $this->base64UrlEncode($raw);
    }

    public function extractGeneratedMessageId(string $rawBase64Url): ?string
    {
        $decoded = base64_decode(strtr($rawBase64Url, '-_', '+/'), true);

        if (! is_string($decoded)) {
            return null;
        }

        if (preg_match('/^Message-ID:\s*(.+)$/mi', $decoded, $matches) === 1) {
            return $this->normalizeMessageId(trim($matches[1]));
        }

        return null;
    }

    private function sanitizeAddress(string $email): string
    {
        return str_replace(["\r", "\n", ',', ';'], '', trim($email));
    }

    private function normalizeMessageId(string $messageId): string
    {
        $trimmed = trim($messageId);

        if ($trimmed === '') {
            return $trimmed;
        }

        if (! str_starts_with($trimmed, '<')) {
            $trimmed = '<'.$trimmed;
        }

        if (! str_ends_with($trimmed, '>')) {
            $trimmed .= '>';
        }

        return $trimmed;
    }

    private function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);

        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?'.base64_encode($value).'?=';
    }

    private function quotedPrintable(string $value): string
    {
        $encoded = quoted_printable_encode($value);

        return str_replace(["\r\n", "\n"], ["\r\n", "\r\n"], $encoded);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
