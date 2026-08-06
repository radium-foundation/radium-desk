<?php

namespace App\Enums;

enum IncomingEmailIgnoreLearningAction: string
{
    case IgnoreOnce = 'ignore_once';
    case AlwaysIgnore = 'always_ignore';
    case VendorUpdate = 'vendor_update';
    case Newsletter = 'newsletter';
    case SystemEmail = 'system_email';

    public function label(): string
    {
        return match ($this) {
            self::IgnoreOnce => 'Ignore once',
            self::AlwaysIgnore => 'Always Ignore',
            self::VendorUpdate => 'Vendor Update',
            self::Newsletter => 'Newsletter',
            self::SystemEmail => 'System Email',
        };
    }

    public function ignoreReason(): string
    {
        return match ($this) {
            self::IgnoreOnce => 'operator_ignore_once',
            self::AlwaysIgnore => 'operator_always_ignore',
            self::VendorUpdate => 'operator_vendor_update',
            self::Newsletter => 'newsletter_or_marketing',
            self::SystemEmail => 'known_system_email',
        };
    }

    public function toStoredClassification(): IncomingEmailClassification
    {
        return match ($this) {
            self::IgnoreOnce,
            self::AlwaysIgnore => IncomingEmailClassification::OtherIgnored,
            self::VendorUpdate => IncomingEmailClassification::VendorAction,
            self::Newsletter => IncomingEmailClassification::Newsletter,
            self::SystemEmail => IncomingEmailClassification::OtherIgnored,
        };
    }

    public function createsPersistentRule(): bool
    {
        return $this !== self::IgnoreOnce;
    }
}
