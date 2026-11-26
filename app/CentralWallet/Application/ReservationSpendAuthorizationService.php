<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Infrastructure\Persistence\CentralWalletReservation;
use InvalidArgumentException;

/**
 * Reservation create authorization and immutable commit binding (P-01-60).
 */
final class ReservationSpendAuthorizationService
{
    public function __construct(
        private readonly WalletVisibilityService $walletVisibility,
        private readonly WalletSpendEligibilityService $spendEligibility,
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * @param  array<string, mixed>|null  $spendAuthorization
     * @return array<string, mixed> Immutable authorization metadata stored on the reservation.
     */
    public function authorizeCreate(
        string $callerId,
        string $requestedCentralWalletId,
        string $amount,
        ?array $spendAuthorization,
    ): array {
        if ($spendAuthorization === null || $spendAuthorization === []) {
            return [
                'authorization' => [
                    'policy_version' => 'legacy_v1',
                    'authorization_mode' => WalletSpendEligibilityService::MODE_LEGACY,
                    'site_code' => $callerId,
                    'central_wallet_id' => $requestedCentralWalletId,
                ],
            ];
        }

        $siteCode = strtolower(trim((string) ($spendAuthorization['site_code'] ?? '')));
        $localUserId = trim((string) ($spendAuthorization['local_user_id'] ?? ''));
        $email = strtolower(trim((string) ($spendAuthorization['email'] ?? '')));
        $emailVerified = filter_var(
            $spendAuthorization['email_verified'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );

        if ($siteCode === '' || $localUserId === '' || $email === '') {
            throw new InvalidArgumentException('spend_authorization_incomplete');
        }

        if ($siteCode !== strtolower(trim($callerId))) {
            throw new InvalidArgumentException('site_mismatch');
        }

        if (! $emailVerified) {
            throw new InvalidArgumentException('spend_authorization_denied');
        }

        if (! $this->spendEligibility->isP01_60Enabled()) {
            throw new InvalidArgumentException('p01_60_spend_disabled');
        }

        $resolution = $this->walletVisibility->resolveWalletIdentityOrFail(
            $siteCode,
            $localUserId,
            $email,
            '',
        );

        $resolvedCwid = (string) ($resolution['central_wallet_id'] ?? '');
        if ($resolvedCwid === '' || $resolvedCwid !== $requestedCentralWalletId) {
            throw new InvalidArgumentException('cwid_mismatch');
        }

        $available = $this->ledger->availableBalance($resolvedCwid);
        $decision = $this->spendEligibility->decide(
            availableBalance: $available,
            verificationMethod: $resolution['verification_method'] ?? null,
            emailVerifiedBySpoke: true,
            matchBasis: (string) ($resolution['match_basis'] ?? ''),
        );

        $allowedModes = [
            WalletSpendEligibilityService::MODE_P01_60_VERIFIED_EMAIL,
            WalletSpendEligibilityService::MODE_TRUSTED_LINK,
        ];

        if (
            ! $decision['spendable']
            || ! in_array((string) ($decision['authorization_mode'] ?? ''), $allowedModes, true)
        ) {
            throw new InvalidArgumentException('spend_authorization_denied');
        }

        if (bccomp($available, $amount, 2) < 0) {
            throw new InvalidArgumentException('Insufficient available balance.');
        }

        try {
            $emailHash = $this->subjectHasher->hashVerifiedEmail($email);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('spend_authorization_denied');
        }

        $authorizationMode = (string) ($decision['authorization_mode'] ?? WalletSpendEligibilityService::MODE_P01_60_VERIFIED_EMAIL);

        return [
            'authorization' => [
                'policy_version' => WalletSpendEligibilityService::POLICY_VERSION,
                'authorization_mode' => $authorizationMode,
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'email_subject_hash' => $emailHash,
                'central_wallet_id' => $resolvedCwid,
                'match_basis' => $resolution['match_basis'],
                'authorized_at_create' => true,
                'p01_60_authorized_at_create' => $authorizationMode === WalletSpendEligibilityService::MODE_P01_60_VERIFIED_EMAIL,
            ],
        ];
    }

    public function assertCommitAllowed(CentralWalletReservation $reservation, string $callerId): void
    {
        $metadata = is_array($reservation->metadata) ? $reservation->metadata : [];
        $authorization = $metadata['authorization'] ?? null;

        if (! is_array($authorization)) {
            return;
        }

        $mode = (string) ($authorization['authorization_mode'] ?? '');

        if ($mode === WalletSpendEligibilityService::MODE_LEGACY) {
            return;
        }

        if (! in_array($mode, [
            WalletSpendEligibilityService::MODE_P01_60_VERIFIED_EMAIL,
            WalletSpendEligibilityService::MODE_TRUSTED_LINK,
        ], true)) {
            throw new InvalidArgumentException('reservation_commit_authorization_invalid');
        }

        $authSite = strtolower(trim((string) ($authorization['site_code'] ?? '')));
        if ($authSite !== strtolower(trim($callerId))) {
            throw new InvalidArgumentException('reservation_commit_caller_mismatch');
        }

        $authCwid = trim((string) ($authorization['central_wallet_id'] ?? ''));
        if ($authCwid === '' || $authCwid !== (string) $reservation->central_wallet_id) {
            throw new InvalidArgumentException('reservation_commit_wallet_mismatch');
        }

        $authorizedAtCreate = filter_var(
            $authorization['authorized_at_create'] ?? $authorization['p01_60_authorized_at_create'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );

        if (! $authorizedAtCreate) {
            throw new InvalidArgumentException('reservation_commit_authorization_invalid');
        }
    }
}
