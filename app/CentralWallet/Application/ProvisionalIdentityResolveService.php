<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Support\TrustedVerificationMethod;
use InvalidArgumentException;

final class ProvisionalIdentityResolveService
{
    public function __construct(
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
        private readonly LedgerService $ledger,
        private readonly AuditEventRecorder $auditEvents,
        private readonly HistoricalCohortProvisionalBalanceService $historicalCohortProvisional,
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
        if (! (bool) config('central_wallet.provisional_identity.enabled', false)) {
            return [
                'status' => 503,
                'body' => ['error' => 'provisional_identity_disabled'],
            ];
        }

        if ($emailVerified) {
            return [
                'status' => 422,
                'body' => ['error' => 'use_trusted_identity_path'],
            ];
        }

        $email = trim($email);
        $credentials = collect();
        if ($email !== '' && str_contains($email, '@')) {
            try {
                $subjectHash = $this->subjectHasher->hashVerifiedEmail($email);
            } catch (InvalidArgumentException) {
                return [
                    'status' => 422,
                    'body' => ['error' => 'verified_email_invalid'],
                ];
            }

            $credentials = CentralCustomerIdentityCredential::query()
                ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
                ->where('provider', 'desk_email')
                ->where('subject_hash', $subjectHash)
                ->get();
        }

        if ($credentials->isEmpty()) {
            return $this->historicalCohortProvisional->resolve(
                siteCode: $siteCode,
                localUserId: $localUserId,
                email: $email !== '' ? $email : null,
                mobile: $mobile,
                correlationId: $correlationId,
            );
        }

        if ($credentials->pluck('desk_customer_id')->unique()->count() > 1) {
            $this->auditEvents->record(
                eventType: 'customer_identity.provisional_ambiguous',
                centralWalletId: null,
                actorType: AuditActorType::Service,
                actorId: 'provisional:'.$siteCode,
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

        $credential = $credentials->first();
        $customer = $credential?->customer;
        if ($customer === null) {
            return [
                'status' => 404,
                'body' => ['error' => 'identity_unresolved', 'identity_state' => 'unresolved'],
            ];
        }

        $existingLink = CentralWalletAccountLink::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->where('status', AccountLinkStatus::Active)
            ->first();

        if ($existingLink !== null) {
            if ($existingLink->central_wallet_id !== $customer->central_wallet_id) {
                return [
                    'status' => 409,
                    'body' => ['error' => 'identity_ambiguous', 'identity_state' => 'unresolved'],
                ];
            }

            if (TrustedVerificationMethod::isTrusted($existingLink->verification_method)) {
                return [
                    'status' => 422,
                    'body' => ['error' => 'use_trusted_identity_path'],
                ];
            }
        }

        try {
            $availableBalance = $this->ledger->spendableBalance($customer->central_wallet_id);
        } catch (\Throwable) {
            return [
                'status' => 503,
                'body' => ['error' => 'balance_unavailable', 'identity_state' => 'unresolved'],
            ];
        }

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

        return [
            'status' => 200,
            'body' => [
                'identity_state' => 'provisional',
                'available_balance' => $availableBalance,
                'currency' => (string) config('central_wallet.currency', 'INR'),
                'verification_required' => true,
            ],
        ];
    }
}
