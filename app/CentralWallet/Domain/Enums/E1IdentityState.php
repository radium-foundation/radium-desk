<?php

namespace App\CentralWallet\Domain\Enums;

enum E1IdentityState: string
{
    case TrustedExistingCwid = 'TRUSTED_EXISTING_CWID';
    case TrustedIdentityNoCwid = 'TRUSTED_IDENTITY_NO_CWID';
    case VerificationAvailable = 'VERIFICATION_AVAILABLE';
    case AmbiguousOrConflicting = 'AMBIGUOUS_OR_CONFLICTING';
    case IdentityInsufficient = 'IDENTITY_INSUFFICIENT';

    public function isDestinationEligible(): bool
    {
        return $this === self::TrustedExistingCwid;
    }

    public function failsClosed(): bool
    {
        return $this === self::AmbiguousOrConflicting;
    }
}
