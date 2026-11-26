<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Support\TrustedVerificationMethod;

/**
 * Authoritative Desk spend eligibility for wallet visibility and reservation (Owner P-01-60).
 */
final class WalletSpendEligibilityService
{
    public const POLICY_VERSION = 'p01_60_v1';

    public const MODE_TRUSTED_LINK = 'trusted_link';

    public const MODE_P01_60_VERIFIED_EMAIL = 'p01_60_verified_email';

    public const MODE_LEGACY = 'trusted_link_legacy';

    public function isP01_60Enabled(): bool
    {
        return filter_var(
            config('central_wallet.spend_authorization.p01_60_verified_email_spend_enabled', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * @return array{
     *     balance_status: string,
     *     spendable: bool,
     *     balance_source: string,
     *     verification_required: bool,
     *     authorization_mode: ?string
     * }
     */
    public function decide(
        string $availableBalance,
        ?string $verificationMethod,
        bool $emailVerifiedBySpoke,
        string $matchBasis,
    ): array {
        if (bccomp($availableBalance, '0', 2) <= 0) {
            return $this->nonSpendable('verification_required', 'central_wallet', true, null);
        }

        if (TrustedVerificationMethod::isTrusted($verificationMethod) && $emailVerifiedBySpoke) {
            return $this->spendable('central_wallet', self::MODE_TRUSTED_LINK);
        }

        if (
            $this->isP01_60Enabled()
            && $emailVerifiedBySpoke
            && in_array($matchBasis, ['account_link', 'credential'], true)
        ) {
            return $this->spendable('central_wallet', self::MODE_P01_60_VERIFIED_EMAIL);
        }

        return $this->nonSpendable('unverified', 'historical_wallet_refund', true, null);
    }

    /**
     * @return array{balance_status: string, spendable: bool, balance_source: string, verification_required: bool, authorization_mode: string}
     */
    private function spendable(string $balanceSource, string $authorizationMode): array
    {
        return [
            'balance_status' => 'verified',
            'spendable' => true,
            'balance_source' => $balanceSource,
            'verification_required' => false,
            'authorization_mode' => $authorizationMode,
        ];
    }

    /**
     * @return array{balance_status: string, spendable: bool, balance_source: string, verification_required: bool, authorization_mode: ?string}
     */
    private function nonSpendable(
        string $balanceStatus,
        string $balanceSource,
        bool $verificationRequired,
        ?string $authorizationMode,
    ): array {
        return [
            'balance_status' => $balanceStatus,
            'spendable' => false,
            'balance_source' => $balanceSource,
            'verification_required' => $verificationRequired,
            'authorization_mode' => $authorizationMode,
        ];
    }
}
