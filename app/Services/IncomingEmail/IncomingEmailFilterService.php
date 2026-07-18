<?php

namespace App\Services\IncomingEmail;

use App\Models\IncomingEmailMessage;

class IncomingEmailFilterService
{
    /**
     * @return array{ignored: bool, reason: ?string}
     */
    public function evaluate(IncomingEmailMessage $message): array
    {
        $labelReason = $this->ignoredLabelReason($message->labels ?? []);

        if ($labelReason !== null) {
            return ['ignored' => true, 'reason' => $labelReason];
        }

        $fromEmail = strtolower(trim((string) $message->from_email));
        $fromName = strtolower(trim((string) $message->from_name));
        $subject = (string) $message->subject;
        $headers = $this->normalizedHeaders($message->headers ?? []);

        if ($this->isBlockedSender($fromEmail)) {
            return ['ignored' => true, 'reason' => 'blocked_sender'];
        }

        if ($this->isBlockedDomain($fromEmail)) {
            return ['ignored' => true, 'reason' => 'blocked_domain'];
        }

        if ($this->isSystemSender($fromEmail, $fromName)) {
            return ['ignored' => true, 'reason' => 'known_system_email'];
        }

        if ($this->isBounceOrDeliverySubsystem($fromEmail, $fromName, $subject, $headers)) {
            return ['ignored' => true, 'reason' => 'bounce_or_delivery_subsystem'];
        }

        if ($this->isAutoResponder($headers, $subject)) {
            return ['ignored' => true, 'reason' => 'auto_responder'];
        }

        if ($this->isNewsletterOrMarketing($headers, $subject)) {
            return ['ignored' => true, 'reason' => 'newsletter_or_marketing'];
        }

        return ['ignored' => false, 'reason' => null];
    }

    /**
     * @param  list<string>|array<int, mixed>  $labels
     */
    private function ignoredLabelReason(array $labels): ?string
    {
        $ignored = array_map('strtoupper', config('inbound_email.ignored_labels', []));
        $normalized = array_map(
            fn (mixed $label): string => strtoupper(trim((string) $label)),
            $labels,
        );

        foreach ($normalized as $label) {
            if (in_array($label, $ignored, true)) {
                return match ($label) {
                    'SPAM' => 'spam',
                    'TRASH' => 'trash',
                    'CATEGORY_PROMOTIONS' => 'promotions',
                    'CATEGORY_SOCIAL' => 'social',
                    default => 'ignored_label:'.$label,
                };
            }
        }

        return null;
    }

    private function isBlockedSender(string $fromEmail): bool
    {
        $blocked = array_map('strtolower', config('inbound_email.blocked_senders', []));

        return in_array($fromEmail, $blocked, true);
    }

    private function isBlockedDomain(string $fromEmail): bool
    {
        $domain = $this->emailDomain($fromEmail);
        $blockedDomains = array_map('strtolower', config('inbound_email.blocked_domains', []));

        return $domain !== null && in_array($domain, $blockedDomains, true);
    }

    private function emailDomain(string $email): ?string
    {
        $parts = explode('@', $email);

        if (count($parts) !== 2 || $parts[1] === '') {
            return null;
        }

        return strtolower($parts[1]);
    }

    private function isSystemSender(string $fromEmail, string $fromName): bool
    {
        foreach (config('inbound_email.system_sender_patterns', []) as $pattern) {
            if ($pattern !== '' && str_contains($fromEmail, strtolower((string) $pattern))) {
                return true;
            }
        }

        foreach (config('inbound_email.system_from_names', []) as $name) {
            if ($name !== '' && str_contains($fromName, strtolower((string) $name))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function isBounceOrDeliverySubsystem(
        string $fromEmail,
        string $fromName,
        string $subject,
        array $headers,
    ): bool {
        if (str_contains($fromName, 'mail delivery subsystem')
            || str_contains($fromName, 'mail delivery system')
            || str_contains($fromEmail, 'mailer-daemon@')
            || str_contains($fromEmail, 'postmaster@')) {
            return true;
        }

        if (isset($headers['x-failed-recipients']) || isset($headers['diagnostic-code'])) {
            return true;
        }

        return (bool) preg_match('/undeliverable|delivery status notification|mail delivery failed|failure notice/i', $subject);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function isAutoResponder(array $headers, string $subject): bool
    {
        $tokens = config('inbound_email.auto_responder_header_tokens', []);

        foreach ($tokens as $token) {
            $key = strtolower((string) $token);

            if (! isset($headers[$key])) {
                continue;
            }

            $value = strtolower($headers[$key]);

            if ($key === 'auto-submitted' && $value !== '' && $value !== 'no') {
                return true;
            }

            if ($key === 'precedence' && in_array($value, ['bulk', 'junk', 'list'], true)) {
                return true;
            }

            if (in_array($key, ['x-autoreply', 'x-autorespond', 'x-auto-response-suppress'], true)) {
                return true;
            }
        }

        return (bool) preg_match('/^out of office|^automatic reply|^auto[:\s-]*reply/i', $subject);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function isNewsletterOrMarketing(array $headers, string $subject): bool
    {
        if (isset($headers['list-unsubscribe']) || isset($headers['list-id'])) {
            return true;
        }

        foreach (config('inbound_email.ignore_subject_patterns', []) as $pattern) {
            if (is_string($pattern) && @preg_match($pattern, $subject) === 1) {
                if (str_contains($pattern, 'newsletter') || str_contains($pattern, 'unsubscribe')) {
                    return true;
                }
            }
        }

        return (bool) preg_match('/\bnewsletter\b|\bunsubscribe\b/i', $subject);
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function normalizedHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $normalized[strtolower(trim((string) $key))] = strtolower(trim((string) $value));
        }

        return $normalized;
    }
}
