<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Support\TrustedVerificationMethod;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class HistoricalWalletVisibilityService
{
    public function __construct(
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
        private readonly LedgerService $ledger,
        private readonly AuditEventRecorder $auditEvents,
        private readonly HistoricalContactIdentityMatchService $contactIdentityMatch,
        private readonly E1ProvisionalDisplayService $e1ProvisionalDisplay,
        private readonly HistoricalCohortProvisionalBalanceService $historicalCohortProvisional,
        private readonly E2ProvisionalDisplayService $e2ProvisionalDisplay,
    ) {}

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function resolve(
        string $siteCode,
        string $localUserId,
        string $email,
        bool $emailVerified,
        ?string $correlationId = null,
        ?string $mobile = null,
    ): array {
        if (! $this->isEnabled()) {
            return [
                'status' => 503,
                'body' => ['error' => 'historical_wallet_visibility_disabled'],
            ];
        }

        $email = trim($email);

        $trustedLink = $this->findActiveTrustedLink($siteCode, $localUserId);
        if ($trustedLink !== null) {
            try {
                $trustedBalance = $this->ledger->spendableBalance($trustedLink->central_wallet_id);
                if (bccomp($trustedBalance, '0', 2) > 0) {
                    return $this->normalize(
                        $this->resolveTrustedCentralWalletBalance(
                            $trustedLink->central_wallet_id,
                            $correlationId,
                            $siteCode,
                            $localUserId,
                        ),
                    );
                }
            } catch (\Throwable) {
                return $this->normalize([
                    'status' => 503,
                    'body' => ['error' => 'balance_unavailable', 'identity_state' => 'unresolved'],
                ]);
            }
        }

        $contactMatch = $this->contactIdentityMatch->resolve(
            $siteCode,
            $localUserId,
            $email !== '' ? $email : null,
            $mobile,
            $correlationId,
        );
        if ($contactMatch !== null && $this->shouldReturnImmediately($contactMatch)) {
            return $this->normalize($contactMatch);
        }

        $credentials = $this->findVerifiedEmailCredentials($email);
        if ($credentials->isNotEmpty()) {
            return $this->resolveCredentialPath($siteCode, $localUserId, $credentials, $correlationId);
        }

        $e1 = $this->e1ProvisionalDisplay->resolve($siteCode, $localUserId, $email !== '' ? $email : null, $mobile, $correlationId);
        if ($this->shouldReturnImmediately($e1)) {
            return $this->normalize($e1);
        }

        $historical = $this->historicalCohortProvisional->resolve(
            siteCode: $siteCode,
            localUserId: $localUserId,
            email: $email !== '' ? $email : null,
            mobile: $mobile,
            correlationId: $correlationId,
        );

        if ($this->shouldReturnImmediately($historical)) {
            return $this->normalize($historical);
        }

        $e2 = $this->e2ProvisionalDisplay->resolve(
            siteCode: $siteCode,
            localUserId: $localUserId,
            email: $email !== '' ? $email : null,
            mobile: $mobile,
            correlationId: $correlationId,
        );

        return $this->normalize($e2);
    }

    public function isEnabled(): bool
    {
        return (bool) config('central_wallet.historical_wallet_visibility.enabled', false)
            || (bool) config('central_wallet.provisional_identity.enabled', false);
    }

    private function findActiveTrustedLink(string $siteCode, string $localUserId): ?CentralWalletAccountLink
    {
        $link = CentralWalletAccountLink::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->where('status', AccountLinkStatus::Active)
            ->whereNotNull('verification_method')
            ->where('verification_method', '!=', '')
            ->orderByDesc('id')
            ->first();

        if ($link === null || ! TrustedVerificationMethod::isTrusted($link->verification_method)) {
            return null;
        }

        return $link;
    }

    /**
     * @param  Collection<int, CentralCustomerIdentityCredential>  $credentials
     * @return array{status: int, body: array<string, mixed>}
     */
    private function resolveCredentialPath(
        string $siteCode,
        string $localUserId,
        $credentials,
        ?string $correlationId,
    ): array {
        if ($credentials->pluck('desk_customer_id')->unique()->count() > 1) {
            $this->auditEvents->record(
                eventType: 'customer_identity.provisional_ambiguous',
                centralWalletId: null,
                actorType: AuditActorType::Service,
                actorId: 'visibility:'.$siteCode,
                correlationId: $correlationId,
                payload: [
                    'site_code' => $siteCode,
                    'local_user_id' => $localUserId,
                ],
            );

            return $this->normalize([
                'status' => 409,
                'body' => ['error' => 'identity_ambiguous', 'identity_state' => 'unresolved'],
            ]);
        }

        $credential = $credentials->first();
        $customer = $credential?->customer;
        if ($customer === null) {
            return $this->normalize([
                'status' => 404,
                'body' => ['error' => 'identity_unresolved', 'identity_state' => 'unresolved'],
            ]);
        }

        $existingLink = CentralWalletAccountLink::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->where('status', AccountLinkStatus::Active)
            ->first();

        if ($existingLink !== null) {
            if ($existingLink->central_wallet_id !== $customer->central_wallet_id) {
                return $this->normalize([
                    'status' => 409,
                    'body' => ['error' => 'identity_ambiguous', 'identity_state' => 'unresolved'],
                ]);
            }

            if (TrustedVerificationMethod::isTrusted($existingLink->verification_method)) {
                return $this->normalize(
                    $this->resolveTrustedCentralWalletBalance(
                        $customer->central_wallet_id,
                        $correlationId,
                        $siteCode,
                        $localUserId,
                    ),
                );
            }
        }

        try {
            $availableBalance = $this->ledger->spendableBalance($customer->central_wallet_id);
        } catch (\Throwable) {
            return $this->normalize([
                'status' => 503,
                'body' => ['error' => 'balance_unavailable', 'identity_state' => 'unresolved'],
            ]);
        }

        if (bccomp($availableBalance, '0', 2) > 0) {
            $this->auditEvents->record(
                eventType: 'customer_identity.provisional_balance_resolved',
                centralWalletId: $customer->central_wallet_id,
                actorType: AuditActorType::Customer,
                actorId: 'customer:'.$localUserId,
                correlationId: $correlationId,
                payload: [
                    'site_code' => $siteCode,
                    'local_user_id' => $localUserId,
                ],
            );

            return $this->normalize([
                'status' => 200,
                'body' => [
                    'identity_state' => 'provisional',
                    'verification_status' => 'unverified',
                    'available_balance' => $availableBalance,
                    'balance_source' => 'central_wallet',
                    'currency' => (string) config('central_wallet.currency', 'INR'),
                    'verification_required' => true,
                    'financial_use_requires_verification' => true,
                ],
            ]);
        }

        return $this->normalize([
            'status' => 404,
            'body' => ['error' => 'identity_unresolved', 'identity_state' => 'unresolved'],
        ]);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function resolveTrustedCentralWalletBalance(
        string $centralWalletId,
        ?string $correlationId,
        string $siteCode,
        string $localUserId,
    ): array {
        try {
            $availableBalance = $this->ledger->spendableBalance($centralWalletId);
        } catch (\Throwable) {
            return [
                'status' => 503,
                'body' => ['error' => 'balance_unavailable', 'identity_state' => 'unresolved'],
            ];
        }

        $this->auditEvents->record(
            eventType: 'customer_identity.verified_balance_resolved',
            centralWalletId: $centralWalletId,
            actorType: AuditActorType::Customer,
            actorId: 'customer:'.$localUserId,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
            ],
        );

        return [
            'status' => 200,
            'body' => [
                'identity_state' => 'verified',
                'available_balance' => $availableBalance,
                'balance_source' => 'central_wallet',
                'currency' => (string) config('central_wallet.currency', 'INR'),
                'verification_required' => false,
                'spendable' => true,
            ],
        ];
    }

    /**
     * @return Collection<int, CentralCustomerIdentityCredential>
     */
    private function findVerifiedEmailCredentials(string $email)
    {
        if ($email === '' || ! str_contains($email, '@')) {
            return collect();
        }

        try {
            $subjectHash = $this->subjectHasher->hashVerifiedEmail($email);
        } catch (InvalidArgumentException) {
            return collect();
        }

        return CentralCustomerIdentityCredential::query()
            ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
            ->where('provider', 'desk_email')
            ->where('subject_hash', $subjectHash)
            ->get();
    }

    /**
     * @param  array{status: int, body: array<string, mixed>}  $result
     */
    private function shouldReturnImmediately(array $result): bool
    {
        $status = $result['status'];
        $error = (string) ($result['body']['error'] ?? '');

        if ($status === 200 || $status === 409 || $status === 422) {
            return true;
        }

        if ($error === 'source_reconciliation_required') {
            return false;
        }

        return $status !== 404;
    }

    /**
     * @param  array{status: int, body: array<string, mixed>}  $result
     * @return array{status: int, body: array<string, mixed>}
     */
    private function normalize(array $result): array
    {
        return (new HistoricalWalletVisibilityResponse($result['status'], $result['body']))->toHttpResult();
    }
}
