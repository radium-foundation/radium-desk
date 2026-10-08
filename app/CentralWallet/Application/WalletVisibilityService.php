<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
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
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
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
            $resolution = $this->resolveWalletIdentity($siteCode, $localUserId, $email, $mobileDigits);
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
     * @return array{central_wallet_id: string, verification_method: ?string, match_basis: string}|null
     */
    private function resolveWalletIdentity(
        string $siteCode,
        string $localUserId,
        string $email,
        string $mobileDigits,
    ): ?array {
        $link = CentralWalletAccountLink::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->where('status', AccountLinkStatus::Active)
            ->first();

        if ($link !== null) {
            if (! $this->contactMatchesLinkedCustomer((string) $link->desk_customer_id, $email, $mobileDigits)) {
                return null;
            }

            return [
                'central_wallet_id' => (string) $link->central_wallet_id,
                'verification_method' => $link->verification_method,
                'match_basis' => 'account_link',
            ];
        }

        $credentialCwids = $this->resolveCwidsFromCredentials($email, $mobileDigits);
        if (count($credentialCwids) > 1) {
            throw new InvalidArgumentException('credential_conflict');
        }

        if (count($credentialCwids) === 1) {
            $customer = CentralCustomer::query()
                ->where('central_wallet_id', $credentialCwids[0])
                ->first();

            $linkForCustomer = $customer !== null
                ? CentralWalletAccountLink::query()
                    ->where('site_code', $siteCode)
                    ->where('local_user_id', $localUserId)
                    ->where('desk_customer_id', $customer->id)
                    ->where('status', AccountLinkStatus::Active)
                    ->first()
                : null;

            return [
                'central_wallet_id' => $credentialCwids[0],
                'verification_method' => $linkForCustomer?->verification_method,
                'match_basis' => 'credential',
            ];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function resolveCwidsFromCredentials(string $email, string $mobileDigits): array
    {
        $customerIds = collect();

        if ($email !== '') {
            try {
                $emailHash = $this->subjectHasher->hashVerifiedEmail($email);
            } catch (InvalidArgumentException) {
                $emailHash = null;
            }

            if ($emailHash !== null) {
                $emailMatches = CentralCustomerIdentityCredential::query()
                    ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
                    ->where('provider', 'desk_email')
                    ->where('subject_hash', $emailHash)
                    ->whereNotNull('verified_at')
                    ->pluck('desk_customer_id');

                if ($emailMatches->count() > 1) {
                    throw new InvalidArgumentException('ambiguous_email_identity');
                }

                $customerIds = $customerIds->merge($emailMatches);
            }
        }

        if (strlen($mobileDigits) >= 10) {
            $e164 = '+91'.ltrim($mobileDigits, '0');
            if (strlen($mobileDigits) === 10) {
                $e164 = '+91'.$mobileDigits;
            }

            try {
                $mobileHash = $this->subjectHasher->hashVerifiedMobileE164($e164);
            } catch (InvalidArgumentException) {
                $mobileHash = null;
            }

            if ($mobileHash !== null) {
                $mobileMatches = CentralCustomerIdentityCredential::query()
                    ->where('credential_type', CustomerIdentityCredentialType::VerifiedMobile)
                    ->where('provider', 'desk_mobile')
                    ->where('subject_hash', $mobileHash)
                    ->whereNotNull('verified_at')
                    ->pluck('desk_customer_id');

                if ($mobileMatches->count() > 1) {
                    throw new InvalidArgumentException('ambiguous_mobile_identity');
                }

                if ($mobileMatches->isNotEmpty()) {
                    if ($customerIds->isNotEmpty() && $mobileMatches->first() !== $customerIds->first()) {
                        throw new InvalidArgumentException('credential_conflict');
                    }

                    $customerIds = $customerIds->merge($mobileMatches);
                }
            }
        }

        if ($customerIds->unique()->count() > 1) {
            throw new InvalidArgumentException('credential_conflict');
        }

        if ($customerIds->isEmpty()) {
            return [];
        }

        $cwid = CentralCustomer::query()
            ->where('id', $customerIds->first())
            ->value('central_wallet_id');

        return is_string($cwid) && trim($cwid) !== '' ? [(string) $cwid] : [];
    }

    private function contactMatchesLinkedCustomer(string $deskCustomerId, string $email, string $mobileDigits): bool
    {
        if ($email !== '') {
            try {
                $emailHash = $this->subjectHasher->hashVerifiedEmail($email);
            } catch (InvalidArgumentException) {
                return false;
            }

            $emailMatch = CentralCustomerIdentityCredential::query()
                ->where('desk_customer_id', $deskCustomerId)
                ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
                ->where('provider', 'desk_email')
                ->where('subject_hash', $emailHash)
                ->whereNotNull('verified_at')
                ->exists();

            if ($emailMatch) {
                return true;
            }
        }

        if (strlen($mobileDigits) >= 10) {
            $e164 = '+91'.$mobileDigits;

            try {
                $mobileHash = $this->subjectHasher->hashVerifiedMobileE164($e164);
            } catch (InvalidArgumentException) {
                return false;
            }

            return CentralCustomerIdentityCredential::query()
                ->where('desk_customer_id', $deskCustomerId)
                ->where('credential_type', CustomerIdentityCredentialType::VerifiedMobile)
                ->where('provider', 'desk_mobile')
                ->where('subject_hash', $mobileHash)
                ->whereNotNull('verified_at')
                ->exists();
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function visibilityBody(
        string $availableBalance,
        string $currency,
        ?string $verificationMethod,
        bool $emailVerifiedBySpoke,
    ): array {
        if (bccomp($availableBalance, '0', 2) <= 0) {
            return [
                'wallet_balance' => $availableBalance,
                'available_balance' => $availableBalance,
                'currency' => $currency,
                'balance_status' => 'verification_required',
                'balance_source' => 'central_wallet',
                'spendable' => false,
                'verification_required' => true,
                'display_label' => 'Wallet',
                'display_hint' => 'Connect and verify your account to view your wallet balance.',
            ];
        }

        $trusted = TrustedVerificationMethod::isTrusted($verificationMethod) && $emailVerifiedBySpoke;

        if ($trusted) {
            return [
                'wallet_balance' => $availableBalance,
                'available_balance' => $availableBalance,
                'currency' => $currency,
                'balance_status' => 'verified',
                'balance_source' => 'central_wallet',
                'spendable' => true,
                'verification_required' => false,
                'display_label' => 'Wallet',
                'display_hint' => 'Available balance',
            ];
        }

        return [
            'wallet_balance' => $availableBalance,
            'available_balance' => $availableBalance,
            'currency' => $currency,
            'balance_status' => 'unverified',
            'balance_source' => 'historical_wallet_refund',
            'spendable' => false,
            'verification_required' => true,
            'display_label' => 'Wallet Balance — Unverified',
            'display_hint' => 'Verify your account to use this balance.',
        ];
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
