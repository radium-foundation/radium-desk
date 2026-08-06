<?php

namespace App\Enums;

enum IncomingEmailIntakeQueue: string
{
    case NeedsHuman = 'needs_human';
    case ReviewSuggested = 'review_suggested';
    case Promotional = 'promotional';
    case Spam = 'spam';
    case Automatic = 'automatic';

    public function label(): string
    {
        return match ($this) {
            self::NeedsHuman => 'Needs Human Action',
            self::ReviewSuggested => 'Review Suggested',
            self::Promotional => 'Promotional',
            self::Spam => 'Spam',
            self::Automatic => 'Completed Automatically',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::NeedsHuman => '📧',
            self::ReviewSuggested => 'R',
            self::Promotional => 'P',
            self::Spam => 'S',
            self::Automatic => 'A',
        };
    }

    public function tooltip(): string
    {
        return match ($this) {
            self::NeedsHuman => 'Emails waiting for an operator.',
            self::ReviewSuggested => 'Emails IRA is uncertain about — review suggested (routing unchanged).',
            self::Promotional => 'Promotional emails ignored automatically.',
            self::Spam => 'Spam detected automatically.',
            self::Automatic => 'Emails completed automatically by IRA (no operator action needed).',
        };
    }

    public function usesSuperscriptCount(): bool
    {
        return $this !== self::NeedsHuman;
    }

    /**
     * Presentation-only subset of Needs Human. Does not change routing.
     */
    public function isReviewSuggestedView(): bool
    {
        return $this === self::ReviewSuggested;
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
            self::NeedsHuman, self::ReviewSuggested => [],
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
            self::NeedsHuman, self::ReviewSuggested => [],
        };
    }

    /**
     * @return list<IncomingEmailMessageStatus>
     */
    public function humanActionStatuses(): array
    {
        return match ($this) {
            self::NeedsHuman, self::ReviewSuggested => [
                IncomingEmailMessageStatus::NeedsReview,
                IncomingEmailMessageStatus::Failed,
            ],
            default => [],
        };
    }
}
