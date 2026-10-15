<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Support\TrustedVerificationMethod;
use InvalidArgumentException;
use Throwable;

/**
 * Read-only wallet visibility for authenticated spokes.
 * Resolves identity server-side and reads balance through LedgerService only.
 */
final class WalletVisibilityService
{
    public function __construct(
        private readonly WalletDisplayIdentityResolver $displayIdentityResolver,
        private readonly LedgerService $ledger,
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
        bool $emailVerifiedBySpoke,
        ?string $correlationId = null,
    ): array {
        if (! $this->isEnabled()) {
            return [
                'status' => 503,
                'body' => ['error' => 'historical_wallet_visibility_disabled'],
            ];
        }

        $email = strtolower(trim((string) $email));
        $mobileDigits = preg_replace('/\D+/', '', (string) $mobile) ?? '';

        if ($email === '' && strlen($mobileDigits) < 10) {
            return [
                'status' => 422,
                'body' => ['error' => 'contact_data_required'],
            ];
        }

        try {
            $resolution = $this->displayIdentityResolver->resolve($siteCode, $localUserId, $email, $mobile !== '' ? $mobile : null);
        } catch (InvalidArgumentException $exception) {
            if (in_array($exception->getMessage(), [
                'ambiguous_email_identity',
                'ambiguous_mobile_identity',
                'credential_conflict',
            ], true)) {
                return $this->ambiguous($siteCode, $localUserId, $correlationId, 'identity_ambiguous');
            }

            return [
                'status' => 404,
                'body' => ['error' => 'not_found'],
            ];
        } catch (Throwable) {
            return [
                'status' => 503,
                'body' => ['error' => 'unavailable'],
            ];
        }

        if ($resolution === null) {
            return [
                'status' => 404,
                'body' => ['error' => 'not_found'],
            ];
        }

        ['central_wallet_id' => $cwid, 'verification_method' => $verificationMethod, 'match_basis' => $matchBasis] = $resolution;

        $wallet = CentralWallet::query()->find($cwid);
        if ($wallet === null) {
            return [
                'status' => 404,
                'body' => ['error' => 'not_found'],
            ];
        }

        $available = $this->ledger->availableBalance($cwid);
        $currency = (string) config('central_wallet.currency', 'INR');

        $this->auditEvents->record(
            eventType: 'wallet_visibility.read',
            centralWalletId: $cwid,
            actorType: AuditActorType::Service,
            actorId: $siteCode.':'.$localUserId,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'match_basis' => $matchBasis,
            ],
        );

        return [
            'status' => 200,
            'body' => $this->visibilityBody(
                availableBalance: $available,
                currency: $currency,
                verificationMethod: $verificationMethod,
                emailVerifiedBySpoke: $emailVerifiedBySpoke,
                centralWalletId: $cwid,
            ),
        ];
    }

    public function isEnabled(): bool
    {
        return filter_var(
            config('central_wallet.historical_wallet_visibility.enabled', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function visibilityBody(
        string $availableBalance,
        string $currency,
        ?string $verificationMethod,
        bool $emailVerifiedBySpoke,
        ?string $centralWalletId = null,
    ): array {
        if (bccomp($availableBalance, '0', 2) <= 0) {
            return $this->withDeskAssertedCentralWalletId([
                'wallet_balance' => $availableBalance,
                'available_balance' => $availableBalance,
                'currency' => $currency,
                'balance_status' => 'verification_required',
                'balance_source' => 'central_wallet',
                'spendable' => false,
                'verification_required' => true,
                'display_label' => 'Wallet',
                'display_hint' => 'Connect and verify your account to view your wallet balance.',
            ], $centralWalletId);
        }

        $trusted = TrustedVerificationMethod::isTrusted($verificationMethod) && $emailVerifiedBySpoke;

        if ($trusted) {
            return $this->withDeskAssertedCentralWalletId([
                'wallet_balance' => $availableBalance,
                'available_balance' => $availableBalance,
                'currency' => $currency,
                'balance_status' => 'verified',
                'balance_source' => 'central_wallet',
                'spendable' => true,
                'verification_required' => false,
                'display_label' => 'Wallet',
                'display_hint' => 'Available balance',
            ], $centralWalletId);
        }

        return $this->withDeskAssertedCentralWalletId([
            'wallet_balance' => $availableBalance,
            'available_balance' => $availableBalance,
            'currency' => $currency,
            'balance_status' => 'unverified',
            'balance_source' => 'historical_wallet_refund',
            'spendable' => false,
            'verification_required' => true,
            'display_label' => 'Wallet Balance — Unverified',
            'display_hint' => 'Verify your account to use this balance.',
        ], $centralWalletId);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function withDeskAssertedCentralWalletId(array $body, ?string $centralWalletId): array
    {
        if (is_string($centralWalletId) && trim($centralWalletId) !== '') {
            $body['central_wallet_id'] = trim($centralWalletId);
        }

        return $body;
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function ambiguous(
        string $siteCode,
        string $localUserId,
        ?string $correlationId,
        string $reason,
    ): array {
        $this->auditEvents->record(
            eventType: 'wallet_visibility.ambiguous',
            centralWalletId: null,
            actorType: AuditActorType::Service,
            actorId: $siteCode.':'.$localUserId,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'reason' => $reason,
            ],
        );

        return [
            'status' => 409,
            'body' => ['error' => 'identity_ambiguous'],
        ];
    }
}
