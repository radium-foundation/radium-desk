<?php

namespace App\CentralWallet\Domain;

final class CeremonyVerificationProof
{
    public function __construct(
        public readonly string $jti,
        public readonly string $siteCode,
        public readonly string $localUserId,
        public readonly string $ceremonyAttemptId,
        public readonly string $verifiedPhoneE164Hash,
        public readonly string $verificationMethod,
        public readonly int $issuedAt,
        public readonly int $expiresAt,
    ) {}

    public function isExpired(int $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isIssuedInFuture(int $now, int $clockSkewSeconds = 60): bool
    {
        return $this->issuedAt > ($now + $clockSkewSeconds);
    }
}
