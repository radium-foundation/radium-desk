<?php

namespace App\CentralWallet\Support;

final class AccountLinkIdentityMetadata
{
    public const VERIFIED_EMAIL_SUBJECT_HASH = 'verified_email_subject_hash';

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    public static function withVerifiedEmailSubjectHash(?array $metadata, string $subjectHash): array
    {
        $metadata ??= [];
        $metadata[self::VERIFIED_EMAIL_SUBJECT_HASH] = $subjectHash;

        return $metadata;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public static function verifiedEmailSubjectHash(?array $metadata): ?string
    {
        $hash = trim((string) ($metadata[self::VERIFIED_EMAIL_SUBJECT_HASH] ?? ''));

        return $hash !== '' ? $hash : null;
    }
}
