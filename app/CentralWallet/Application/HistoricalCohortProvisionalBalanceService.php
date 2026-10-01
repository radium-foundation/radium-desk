<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Support\TrustedVerificationMethod;
use InvalidArgumentException;

final class HistoricalCohortProvisionalBalanceService
{
    public function __construct(
        private readonly IdentityRequiredCohortManifestLoader $manifestLoader,
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
        if (! (bool) config('central_wallet.identity_required_cohort.provisional_display_enabled', false)) {
            return $this->unresolved('historical_cohort_provisional_disabled');
        }

        if (! $this->hasUsableContactData($email, $mobile)) {
            return $this->unresolved('contact_data_required');
        }

        try {
            $manifest = $this->manifestLoader->load();
        } catch (InvalidArgumentException) {
            return [
                'status' => 503,
                'body' => ['error' => 'identity_required_cohort_manifest_unavailable', 'identity_state' => 'unresolved'],
            ];
        }

        $cohortMember = $this->manifestLoader->findSiteUser($manifest, $siteCode, $localUserId);
        if ($cohortMember === null) {
            return $this->unresolved('not_in_identity_required_cohort');
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
                eventType: 'customer_identity.historical_cohort_provisional_ambiguous',
                centralWalletId: $existingLink->central_wallet_id,
                actorType: AuditActorType::Service,
                actorId: 'historical_cohort:'.$siteCode,
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

        if (! (bool) ($cohortMember['source_wallet_resolved'] ?? false)) {
            return [
                'status' => 404,
                'body' => [
                    'error' => 'source_reconciliation_required',
                    'identity_state' => 'unresolved',
                    'blocker' => 'authoritative_source_wallet_unresolved',
                ],
            ];
        }

        $balance = (string) ($cohortMember['spendable_balance'] ?? '0.00');
        if (bccomp($balance, '0', 2) < 0) {
            return $this->unresolved('invalid_source_balance');
        }

        $this->auditEvents->record(
            eventType: 'customer_identity.historical_cohort_provisional_balance_resolved',
            centralWalletId: null,
            actorType: AuditActorType::Customer,
            actorId: 'customer:'.$localUserId,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'cohort_id' => IdentityRequiredCohortManifestLoader::COHORT_ID,
                'refund_count' => count($cohortMember['refund_ids'] ?? []),
            ],
        );

        return [
            'status' => 200,
            'body' => [
                'identity_state' => 'provisional',
                'verification_status' => 'unverified',
                'available_balance' => $balance,
                'balance_source' => 'local_spoke_wallet',
                'currency' => (string) config('central_wallet.currency', 'INR'),
                'verification_required' => true,
                'verification_paths' => [
                    'verified_email_otp',
                    'verified_mobile_otp',
                    'google_sign_in',
                ],
                'message' => 'Verify your email, mobile, or continue with Google to use this balance.',
                'financial_use_requires_verification' => true,
                'historical_migration_separate' => true,
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
