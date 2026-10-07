<?php

namespace App\CentralWallet\Support;

final class TrustedVerificationMethod
{
    /** @var list<string> */
    private const TRUSTED = [
        'trusted_google',
        'verified_email',
        'm2_dual_otp',
        'm2_whatsapp_otp',
    ];

    public static function isTrusted(?string $verificationMethod): bool
    {
        $method = trim((string) $verificationMethod);

        return $method !== '' && in_array($method, self::TRUSTED, true);
    }
}
