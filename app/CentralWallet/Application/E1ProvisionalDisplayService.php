<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Support\TrustedVerificationMethod;
use InvalidArgumentException;

final class E1ProvisionalDisplayService
{
    public function __construct(
        private readonly E1CohortManifestLoader $manifestLoader,
        private readonly ReconciledHistoricalRefundFilter $reconciledFilter,
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
        if (! (bool) config('central_wallet.e1_identity_migration.verification_enabled', false)) {
            return $this->unresolved('e1_verification_disabled');
        }

        if (! $this->hasUsableContactData($email, $mobile)) {
            return $this->unresolved('contact_data_required');
        }

        try {
            $manifest = $this->manifestLoader->load();
        } catch (InvalidArgumentException) {
            return [
                'status' => 503,
                'body' => ['error' => 'e1_verification_cohort_manifest_unavailable', 'identity_state' => 'unresolved'],
            ];
        }

        $matches = $this->manifestLoader->findBySiteUser($manifest, $siteCode, $localUserId);
        if ($matches === []) {
            return $this->unresolved('not_in_e1_verification_cohort');
        }

        $linkError = $this->checkAccountLinkAmbiguity($siteCode, $localUserId, $correlationId);
        if ($linkError !== null) {
            return $linkError;
        }

        $balance = $this->reconciledFilter->sumDisplayableAmounts($matches);
        if (bccomp($balance, '0', 2) <= 0) {
            return $this->unresolved('historical_balance_already_settled');
        }

        $this->auditEvents->record(
            eventType: 'customer_identity.e1_provisional_balance_resolved',
            centralWalletId: null,
            actorType: AuditActorType::Customer,
            actorId: 'customer:'.$localUserId,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'cohort_id' => E1CohortManifestLoader::COHORT_ID,
                'refund_count' => count($matches),
            ],
        );

        return $this->provisionalBody($balance, 'historical_wallet_refund');
    }

    /**
     * @return array{status: int, body: array<string, mixed>}|null
     */
    private function checkAccountLinkAmbiguity(string $siteCode, string $localUserId, ?string $correlationId): ?array
    {
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
                eventType: 'customer_identity.e1_provisional_ambiguous',
                centralWalletId: $existingLink->central_wallet_id,
                actorType: AuditActorType::Service,
                actorId: 'e1_provisional:'.$siteCode,
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

        return null;
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function provisionalBody(string $balance, string $balanceSource): array
    {
        return [
            'status' => 200,
            'body' => [
                'identity_state' => 'provisional',
                'verification_status' => 'unverified',
                'available_balance' => $balance,
                'balance_source' => $balanceSource,
                'currency' => (string) config('central_wallet.currency', 'INR'),
                'verification_required' => true,
                'verification_paths' => [
                    'verified_email_otp',
                    'verified_mobile_otp',
                    'google_sign_in',
                ],
                'message' => 'Verify your account to use this balance.',
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
