<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Support\TrustedVerificationMethod;
use InvalidArgumentException;

final class E2ProvisionalDisplayService
{
    public function __construct(
        private readonly E2CohortManifestLoader $manifestLoader,
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function resolve(
        string $siteCode,
        string $localUserId,
        ?string $email,
        ?string $mobile,
        ?string $correlationId = null,
    ): array {
        if (! (bool) config('central_wallet.e2_historical_settlement.verification_enabled', false)) {
            return $this->unresolved('e2_verification_disabled');
        }

        if (! $this->hasUsableContactData($email, $mobile)) {
            return $this->unresolved('contact_data_required');
        }

        $email = trim((string) $email);
        if ($email === '' || ! str_contains($email, '@')) {
            return $this->unresolved('e2_provisional_requires_email');
        }

        try {
            $emailHash = $this->subjectHasher->hashVerifiedEmail($email);
        } catch (InvalidArgumentException) {
            return $this->unresolved('email_invalid');
        }

        try {
            $manifest = $this->manifestLoader->load();
        } catch (InvalidArgumentException) {
            return [
                'status' => 503,
                'body' => ['error' => 'e2_verification_cohort_manifest_unavailable', 'identity_state' => 'unresolved'],
            ];
        }

        $matches = $this->manifestLoader->findBySiteEmailHash($manifest, $siteCode, $emailHash);
        if ($matches === []) {
            return $this->unresolved('not_in_e2_verification_cohort');
        }

        $existingLink = CentralWalletAccountLink::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->where('status', AccountLinkStatus::Active)
            ->first();

        if ($existingLink !== null && TrustedVerificationMethod::isTrusted($existingLink->verification_method)) {
            return [
                'status' => 422,
                'body' => ['error' => 'use_trusted_identity_path'],
            ];
        }

        if ($existingLink !== null && ! TrustedVerificationMethod::isTrusted($existingLink->verification_method)) {
            $this->auditEvents->record(
                eventType: 'customer_identity.e2_provisional_ambiguous',
                centralWalletId: $existingLink->central_wallet_id,
                actorType: AuditActorType::Service,
                actorId: 'e2_provisional:'.$siteCode,
                correlationId: $correlationId,
                payload: [
                    'site_code' => $siteCode,
                    'local_user_id' => $localUserId,
                ],
            );

            return [
                'status' => 409,
                'body' => ['error' => 'identity_ambiguous', 'identity_state' => 'unresolved'],
            ];
        }

        $balance = '0.00';
        foreach ($matches as $row) {
            $amount = (string) ($row['refund_amount'] ?? $row['amount'] ?? '0');
            $balance = bcadd($balance, $amount, 2);
        }

        $this->auditEvents->record(
            eventType: 'customer_identity.e2_provisional_balance_resolved',
            centralWalletId: null,
            actorType: AuditActorType::Customer,
            actorId: 'customer:'.$localUserId,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'cohort_id' => E2CohortManifestLoader::COHORT_ID,
                'refund_count' => count($matches),
            ],
        );

        return [
            'status' => 200,
            'body' => [
                'identity_state' => 'provisional',
                'verification_status' => 'unverified',
                'available_balance' => $balance,
                'balance_source' => 'historical_refund_amount_pending_verification',
                'currency' => (string) config('central_wallet.currency', 'INR'),
                'verification_required' => true,
                'verification_paths' => [
                    'verified_email_otp',
                    'verified_mobile_otp',
                    'google_sign_in',
                ],
                'message' => 'Verify your identity to use this wallet balance.',
                'financial_use_requires_verification' => true,
                'historical_migration_separate' => true,
                'source_wallet_provenance' => 'unavailable_not_reconstructed',
            ],
        ];
    }

    private function hasUsableContactData(?string $email, ?string $mobile): bool
    {
        $email = trim((string) $email);
        if ($email !== '' && str_contains($email, '@')) {
            return true;
        }

        $digits = preg_replace('/\D+/', '', (string) $mobile) ?? '';

        return strlen($digits) >= 10;
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function unresolved(string $reason): array
    {
        return [
            'status' => 404,
            'body' => [
                'error' => 'identity_unresolved',
                'identity_state' => 'unresolved',
                'reason' => $reason,
            ],
        ];
    }
}
