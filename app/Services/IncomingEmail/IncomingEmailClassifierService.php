<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncomingEmailClassification;
use App\Models\IncomingEmailMessage;

class IncomingEmailClassifierService
{
    public function fromFilterReason(string $reason): IncomingEmailClassification
    {
        $normalized = strtolower(trim($reason));

        return match (true) {
            $normalized === 'spam',
            str_starts_with($normalized, 'ignored_label:spam') => IncomingEmailClassification::Spam,
            $normalized === 'promotions',
            str_contains($normalized, 'promotion') => IncomingEmailClassification::Promotional,
            $normalized === 'social',
            str_contains($normalized, 'social') => IncomingEmailClassification::Social,
            $normalized === 'newsletter_or_marketing',
            str_contains($normalized, 'newsletter') => IncomingEmailClassification::Newsletter,
            str_contains($normalized, 'marketing') => IncomingEmailClassification::Marketing,
            str_contains($normalized, 'forum') => IncomingEmailClassification::Forum,
            $normalized === 'own_outbound' => IncomingEmailClassification::OwnOutbound,
            default => IncomingEmailClassification::OtherIgnored,
        };
    }

    /**
     * Classify an operational (non-ignored) message after customer matching.
     *
     * @param  array{order: mixed, incident: mixed, reason: ?string}  $match
     */
    public function classifyOperational(IncomingEmailMessage $message, array $match): IncomingEmailClassification
    {
        if (($match['reason'] ?? null) === 'unknown_customer' || ($match['order'] ?? null) === null) {
            return IncomingEmailClassification::PossibleSalesLead;
        }

        $keywordClass = $this->fromKeywords($message);

        if ($keywordClass !== null) {
            return $keywordClass;
        }

        $channel = strtolower(trim((string) ($message->channel ?? '')));

        return match ($channel) {
            'refund' => IncomingEmailClassification::Refund,
            'sales' => IncomingEmailClassification::PossibleSalesLead,
            'support', 'service' => IncomingEmailClassification::Support,
            default => IncomingEmailClassification::ExistingCustomer,
        };
    }

    private function fromKeywords(IncomingEmailMessage $message): ?IncomingEmailClassification
    {
        $haystack = strtolower(trim(
            (string) $message->subject.' '.
            (string) $message->from_email.' '.
            (string) $message->from_name.' '.
            (string) $message->preview
        ));

        if ($haystack === '') {
            return null;
        }

        if ($this->containsAny($haystack, ['appointment', 'schedule call', 'callback', 'booked slot'])) {
            return IncomingEmailClassification::Appointment;
        }

        if ($this->containsAny($haystack, ['refund', 'chargeback', 'money back'])) {
            return IncomingEmailClassification::Refund;
        }

        if ($this->containsAny($haystack, ['invoice', 'vendor', 'purchase order', 'po #', 'supplier'])) {
            return IncomingEmailClassification::VendorAction;
        }

        if ($this->containsAny($haystack, ['gst', 'tax invoice', 'accounts payable', 'finance team', 'payment remittance'])) {
            return IncomingEmailClassification::FinanceAction;
        }

        if ($this->containsAny($haystack, ['hr team', 'human resources', 'payroll', 'leave policy', 'offer letter'])) {
            return IncomingEmailClassification::HrAction;
        }

        return null;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
