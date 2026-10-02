<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Support\AccountLinkIdentityMetadata;
use App\CentralWallet\Support\TrustedVerificationMethod;

final class CrossCredentialContinuityService
{
    public function __construct(
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    /**
     * @param  array{credential_type: CustomerIdentityCredentialType, provider: string, subject_hash: string}  $credential
     * @return array{status: 'no_match'|'resolved'|'ambiguous', customer?: CentralCustomer}
     */
    public function resolveCustomer(
        string $siteCode,
        string $localUserId,
        array $credential,
        ?string $correlationId = null,
    ): array {
        if ($credential['credential_type'] !== CustomerIdentityCredentialType::VerifiedEmail) {
            return ['status' => 'no_match'];
        }

        $subjectHash = trim((string) $credential['subject_hash']);
        if ($subjectHash === '') {
            return ['status' => 'no_match'];
        }

        $customerIds = CentralWalletAccountLink::query()
            ->where('status', AccountLinkStatus::Active)
            ->whereNotNull('desk_customer_id')
            ->whereNotNull('verification_method')
            ->where('verification_method', '!=', '')
            ->get()
            ->filter(static fn (CentralWalletAccountLink $link): bool => TrustedVerificationMethod::isTrusted($link->verification_method))
            ->filter(static fn (CentralWalletAccountLink $link): bool => AccountLinkIdentityMetadata::verifiedEmailSubjectHash($link->metadata) === $subjectHash)
            ->pluck('desk_customer_id')
            ->unique()
            ->values();

        if ($customerIds->count() > 1) {
            $this->auditEvents->record(
                eventType: 'customer_identity.continuity_ambiguous',
                centralWalletId: null,
                actorType: AuditActorType::Service,
                actorId: 'customer_identity:'.$siteCode,
                correlationId: $correlationId,
                payload: [
                    'site_code' => $siteCode,
                    'local_user_id' => $localUserId,
                    'credential_type' => $credential['credential_type']->value,
                ],
            );

            return ['status' => 'ambiguous'];
        }

        if ($customerIds->count() === 0) {
            return ['status' => 'no_match'];
        }

        $customer = CentralCustomer::query()->find($customerIds->first());
        if ($customer === null) {
            return ['status' => 'no_match'];
        }

        $this->auditEvents->record(
            eventType: 'customer_identity.continuity_resolved',
            centralWalletId: $customer->central_wallet_id,
            actorType: AuditActorType::Service,
            actorId: 'customer_identity:'.$siteCode,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'desk_customer_id' => $customer->id,
                'credential_type' => $credential['credential_type']->value,
            ],
        );

        return [
            'status' => 'resolved',
            'customer' => $customer,
        ];
    }
}
