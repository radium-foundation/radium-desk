<?php

namespace App\Enums;

/**
 * Presentation-only breakdown under Completed Automatically.
 * Does not change ingest, filter, or routing.
 */
enum IncomingEmailAutomaticSubcategory: string
{
    case SystemNotifications = 'system_notifications';
    case AutoReplies = 'auto_replies';
    case OwnOutbound = 'own_outbound';
    case Bounces = 'bounces';
    case DuplicateNotifications = 'duplicate_notifications';

    public function label(): string
    {
        return match ($this) {
            self::SystemNotifications => 'System Notifications',
            self::AutoReplies => 'Auto Replies',
            self::OwnOutbound => 'Own Outbound',
            self::Bounces => 'Bounces',
            self::DuplicateNotifications => 'Duplicate Notifications',
        };
    }

    public function tooltip(): string
    {
        return match ($this) {
            self::SystemNotifications => 'Vendor and platform system notifications completed by IRA.',
            self::AutoReplies => 'Out-of-office and automatic reply messages.',
            self::OwnOutbound => 'Echoes of mail sent from Radium mailboxes.',
            self::Bounces => 'Delivery failure and bounce subsystem messages.',
            self::DuplicateNotifications => 'Repeated notification subjects already completed automatically.',
        };
    }

    /**
     * Underlying ignore_reason for reason-based subcategories.
     */
    public function ignoreReason(): ?string
    {
        return match ($this) {
            self::SystemNotifications => 'known_system_email',
            self::AutoReplies => 'auto_responder',
            self::OwnOutbound => 'own_outbound',
            self::Bounces => 'bounce_or_delivery_subsystem',
            self::DuplicateNotifications => null,
        };
    }

    public static function tryFromIgnoreReason(?string $reason): ?self
    {
        return match ($reason) {
            'known_system_email' => self::SystemNotifications,
            'auto_responder' => self::AutoReplies,
            'own_outbound' => self::OwnOutbound,
            'bounce_or_delivery_subsystem' => self::Bounces,
            default => null,
        };
    }
}
