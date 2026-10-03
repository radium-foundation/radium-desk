<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Support\TrustedVerificationMethod;

final class CustomerLedgerHistoryAuthorizationGate
{
    public function authorize(string $siteCode, string $centralWalletId, ?string $localUserId): ?string
    {
        if (! filter_var(config('central_wallet.ledger_read.customer_history.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            return 'customer_history_read_disabled';
        }

        /** @var list<string> $authorizedCallers */
        $authorizedCallers = config('central_wallet.ledger_read.customer_history.authorized_callers', []);
        if ($authorizedCallers === [] || ! in_array($siteCode, $authorizedCallers, true)) {
            return 'unauthorized_caller';
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
