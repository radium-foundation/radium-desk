<?php

namespace App\Services\StatutoryInvoice;

final class ServiceStatutoryInvoiceMintOutboxErrorSanitizer
{
    private const MAX_LENGTH = 240;

    public function sanitize(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $trimmed = trim($message);
        if ($trimmed === '') {
            return null;
        }

        $normalized = strtolower($trimmed);
        if (str_contains($normalized, 'missing service_commerce')) {
            return 'Upstream commerce payload is incomplete (service commerce data missing).';
        }

        if (str_contains($normalized, 'validation') || str_contains($normalized, 'eligibility')) {
            return 'Invoice mint is blocked by statutory eligibility checks.';
        }

        if (str_contains($normalized, 'deadlock')
            || str_contains($normalized, 'lock wait timeout')
            || str_contains($normalized, 'connection')
            || str_contains($normalized, 'server has gone away')
            || str_contains($normalized, 'too many connections')) {
            return 'Temporary infrastructure issue during invoice mint.';
        }

        $redacted = $this->redactSensitive($trimmed);
        if ($this->looksUnsafe($redacted)) {
            return 'Invoice mint failed. Contact finance operations for details.';
        }

        if (mb_strlen($redacted) > self::MAX_LENGTH) {
            return mb_substr($redacted, 0, self::MAX_LENGTH - 3).'...';
        }

        return $redacted;
    }

    private function redactSensitive(string $message): string
    {
        $message = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[redacted]', $message) ?? $message;
        $message = preg_replace('/\b(?:\+?91[-\s]?)?[6-9]\d{9}\b/', '[redacted]', $message) ?? $message;
        $message = preg_replace('/bearer\s+[A-Za-z0-9._\-]+/i', 'bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/\b(?:select|insert|update|delete)\b.{0,120}/i', '[redacted]', $message) ?? $message;
        $message = preg_replace('/\s+#\d+\s+.*/', '', $message) ?? $message;
        $message = preg_replace('/\s+at\s+\/.*/', '', $message) ?? $message;

        return trim(preg_replace('/\s+/', ' ', $message) ?? $message);
    }

    private function looksUnsafe(string $message): bool
    {
        if ($message === '' || $message === '[redacted]') {
            return true;
        }

        $lower = strtolower($message);

        return str_contains($lower, 'sqlstate')
            || str_contains($lower, 'pdo')
            || str_contains($lower, 'stack trace')
            || str_contains($lower, 'syntax error')
            || str_contains($lower, '{')
            || str_contains($lower, 'http://')
            || str_contains($lower, 'https://');
    }
}
