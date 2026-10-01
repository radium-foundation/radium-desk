<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use InvalidArgumentException;

final class CustomerIdentitySubjectHasher
{
    public function hashGoogleSubject(string $googleSubject): string
    {
        $subject = trim($googleSubject);
        if ($subject === '') {
            throw new InvalidArgumentException('google_subject_required');
        }

        return hash('sha256', 'google:'.$subject);
    }

    public function hashVerifiedEmail(string $email): string
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '' || ! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('verified_email_invalid');
        }

        return hash('sha256', 'verified_email:'.$normalized);
    }

    public function hashVerifiedMobileE164(string $e164): string
    {
        $normalized = preg_replace('/\s+/', '', trim($e164)) ?? '';
        if ($normalized === '' || ! preg_match('/^\+[1-9]\d{6,14}$/', $normalized)) {
            throw new InvalidArgumentException('verified_mobile_invalid');
        }

        return hash('sha256', 'verified_mobile:'.$normalized);
    }

    /**
     * Owner-authorized migration cohort anchor — NOT derived from raw email/mobile.
     *
     * @param  list<int>  $refundIds
     */
    public function hashMigrationCohortAnchor(string $cohortId, string $siteCode, string $localUserId, array $refundIds): string
    {
        $cohortId = trim($cohortId);
        $siteCode = trim($siteCode);
        $localUserId = trim($localUserId);
        if ($cohortId === '' || $siteCode === '' || $localUserId === '') {
            throw new InvalidArgumentException('migration_cohort_anchor_invalid');
        }

        $ids = array_values(array_unique(array_map('intval', $refundIds)));
        sort($ids);
        if ($ids === []) {
            throw new InvalidArgumentException('migration_cohort_refund_ids_required');
        }

        return hash('sha256', 'migration_cohort_anchor:'.$cohortId.':'.$siteCode.':'.$localUserId.':'.implode(',', $ids));
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array{credential_type: CustomerIdentityCredentialType, provider: string, subject_hash: string}
     */
    public function fromTrustedIdentityPayload(array $identity): array
    {
        $type = (string) ($identity['type'] ?? '');
        if ($type === CustomerIdentityCredentialType::Google->value) {
            return [
                'credential_type' => CustomerIdentityCredentialType::Google,
                'provider' => 'google',
                'subject_hash' => $this->hashGoogleSubject((string) ($identity['google_subject'] ?? '')),
            ];
        }

        if ($type === CustomerIdentityCredentialType::VerifiedEmail->value) {
            return [
                'credential_type' => CustomerIdentityCredentialType::VerifiedEmail,
                'provider' => 'desk_email',
                'subject_hash' => $this->hashVerifiedEmail((string) ($identity['email'] ?? '')),
            ];
        }

        if ($type === CustomerIdentityCredentialType::VerifiedMobile->value) {
            return [
                'credential_type' => CustomerIdentityCredentialType::VerifiedMobile,
                'provider' => 'desk_mobile',
                'subject_hash' => $this->hashVerifiedMobileE164((string) ($identity['mobile_e164'] ?? '')),
            ];
        }

        throw new InvalidArgumentException('unsupported_identity_type');
    }
}
