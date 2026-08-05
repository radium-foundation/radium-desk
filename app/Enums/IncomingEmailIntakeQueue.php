<?php

namespace App\Enums;

enum IncomingEmailIntakeQueue: string
{
    case NeedsHuman = 'needs_human';
    case Promotional = 'promotional';
    case Spam = 'spam';
    case Automatic = 'automatic';

    public function label(): string
    {
        return match ($this) {
            self::NeedsHuman => 'Needs Human Action',
            self::Promotional => 'Promotional',
            self::Spam => 'Spam',
            self::Automatic => 'Automatic Replies',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::NeedsHuman => '📧',
            self::Promotional => 'P',
            self::Spam => 'S',
            self::Automatic => 'A',
        };
    }

    public function tooltip(): string
    {
        return match ($this) {
            self::NeedsHuman => 'Emails waiting for an operator.',
            self::Promotional => 'Promotional emails ignored automatically.',
            self::Spam => 'Spam detected automatically.',
            self::Automatic => 'Automatic replies and delivery notifications.',
        };
    }

    public function usesSuperscriptCount(): bool
    {
        return $this !== self::NeedsHuman;
    }

    /**
     * @return list<string>
     */
    public function ignoreReasons(): array
    {
        return match ($this) {
            self::Promotional => [
                'promotions',
                'newsletter_or_marketing',
            ],
            self::Spam => [
                'spam',
                'trash',
            ],
            self::Automatic => [
                'auto_responder',
                'bounce_or_delivery_subsystem',
                'known_system_email',
                'own_outbound',
            ],
            self::NeedsHuman => [],
        };
    }

    /**
     * @return list<IncomingEmailClassification>
     */
    public function ignoredClassifications(): array
    {
        return match ($this) {
            self::Promotional => [
                IncomingEmailClassification::Promotional,
                IncomingEmailClassification::Marketing,
                IncomingEmailClassification::Newsletter,
            ],
            self::Spam => [
                IncomingEmailClassification::Spam,
            ],
            self::Automatic => [
                IncomingEmailClassification::OwnOutbound,
            ],
            self::NeedsHuman => [],
        };
    }

    /**
     * @return list<IncomingEmailMessageStatus>
     */
    public function humanActionStatuses(): array
    {
        return match ($this) {
            self::NeedsHuman => [
                IncomingEmailMessageStatus::NeedsReview,
                IncomingEmailMessageStatus::Failed,
            ],
            default => [],
        };
    }
}
