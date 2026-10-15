<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Support\TrustedVerificationMethod;
use InvalidArgumentException;

final class CustomerLedgerHistoryAuthorizationGate
{
    public function __construct(
        private readonly WalletDisplayIdentityResolver $displayIdentityResolver,
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    public function authorize(
        string $siteCode,
        string $centralWalletId,
        ?string $localUserId,
        ?string $displayEmail = null,
        ?string $correlationId = null,
    ): ?string {
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

        $trustedError = $this->authorizeTrustedAccountLink($siteCode, $centralWalletId, $localUserId);
        if ($trustedError === null) {
            return null;
        }

        if ($trustedError === 'trusted_identity_required') {
            return $trustedError;
        }

        return $this->authorizeDisplayEmailMatch(
            $siteCode,
            $centralWalletId,
            $localUserId,
            $displayEmail,
            $correlationId,
        );
    }

    private function authorizeTrustedAccountLink(string $siteCode, string $centralWalletId, string $localUserId): ?string
    {
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

    private function authorizeDisplayEmailMatch(
        string $siteCode,
        string $centralWalletId,
        string $localUserId,
        ?string $displayEmail,
        ?string $correlationId,
    ): ?string {
        if (! $this->displayEmailMatchEnabled()) {
            return 'trusted_account_link_required';
        }

        if (! filter_var(config('central_wallet.historical_wallet_visibility.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            return 'display_ledger_history_disabled';
        }

        $email = strtolower(trim((string) $displayEmail));
        if ($email === '') {
            return 'display_email_required';
        }

        try {
            $resolution = $this->displayIdentityResolver->resolve($siteCode, $localUserId, $email, null);
        } catch (InvalidArgumentException $exception) {
            if (in_array($exception->getMessage(), [
                'ambiguous_email_identity',
                'ambiguous_mobile_identity',
                'credential_conflict',
            ], true)) {
                return 'identity_ambiguous';
            }

            return 'display_identity_unresolved';
        }

        if ($resolution === null) {
            return 'display_identity_unresolved';
        }

        if ((string) $resolution['central_wallet_id'] !== $centralWalletId) {
            return 'cwid_identity_mismatch';
        }

        $this->auditEvents->record(
            eventType: 'customer_ledger_history.display_email_match_authorized',
            centralWalletId: $centralWalletId,
            actorType: AuditActorType::Service,
            actorId: $siteCode.':'.$localUserId,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'match_basis' => $resolution['match_basis'],
                'read_only' => true,
            ],
        );

        return null;
    }

    private function displayEmailMatchEnabled(): bool
    {
        return filter_var(
            config('central_wallet.ledger_read.customer_history.display_email_match.enabled', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }
}
