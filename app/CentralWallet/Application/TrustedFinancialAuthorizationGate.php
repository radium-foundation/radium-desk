<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Support\TrustedVerificationMethod;

final class TrustedFinancialAuthorizationGate
{
    public function authorizeReservation(
        string $siteCode,
        string $centralWalletId,
        ?string $localUserId,
    ): ?string {
        if (! (bool) config('central_wallet.provisional_identity.financial_gate_enabled', false)) {
            return null;
        }

        $localUserId = trim((string) $localUserId);
        if ($localUserId === '') {
            return 'local_user_id_required';
        }

        $link = CentralWalletAccountLink::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->where('central_wallet_id', $centralWalletId)
            ->where('status', AccountLinkStatus::Active)
            ->first();

        if ($link === null) {
            return 'trusted_account_link_required';
        }

        if (! TrustedVerificationMethod::isTrusted($link->verification_method)) {
            return 'trusted_identity_required';
        }

        return null;
    }
}
