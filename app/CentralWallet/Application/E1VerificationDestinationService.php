<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Domain\Enums\E1MigrationDestinationState;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Facades\DB;

final class E1VerificationDestinationService
{
    public function __construct(
        private readonly E1CohortManifestLoader $cohortManifestLoader,
        private readonly E1IdentityMigrationJournalImportService $journalImport,
        private readonly RefundMigrationTargetAssignmentService $targetAssignment,
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    /**
     * @param  array<string, mixed>  $identity
     * @return array{
     *     attempted: bool,
     *     assigned_refund_ids: list<int>,
     *     skipped_reason?: string
     * }
     */
    public function prepareAfterTrustedVerification(
        string $siteCode,
        string $localUserId,
        string $deskCustomerId,
        string $cwid,
        array $identity,
        ?string $correlationId = null,
    ): array {
        if (! (bool) config('central_wallet.e1_identity_migration.verification_enabled', false)) {
            return ['attempted' => false, 'assigned_refund_ids' => [], 'skipped_reason' => 'e1_verification_disabled'];
        }

        if (! $this->hasTrustedCredentialEvidence($deskCustomerId, $identity)) {
            return ['attempted' => true, 'assigned_refund_ids' => [], 'skipped_reason' => 'no_trusted_credential_evidence'];
        }

        try {
            $cohortManifest = $this->cohortManifestLoader->load();
        } catch (\InvalidArgumentException) {
            return ['attempted' => true, 'assigned_refund_ids' => [], 'skipped_reason' => 'cohort_manifest_unavailable'];
        }

        $matches = $this->cohortManifestLoader->findBySiteUser($cohortManifest, $siteCode, $localUserId);
        if ($matches === []) {
            return ['attempted' => true, 'assigned_refund_ids' => [], 'skipped_reason' => 'no_e1_cohort_match'];
        }

        $link = CentralWalletAccountLink::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->where('status', AccountLinkStatus::Active)
            ->first();

        if ($link === null) {
            return ['attempted' => true, 'assigned_refund_ids' => [], 'skipped_reason' => 'active_link_missing'];
        }

        if ($link->desk_customer_id !== $deskCustomerId || $link->central_wallet_id !== $cwid) {
            $this->auditAmbiguous($siteCode, $localUserId, $correlationId, 'link_customer_mismatch');

            return ['attempted' => true, 'assigned_refund_ids' => [], 'skipped_reason' => 'identity_link_conflict'];
        }

        $customer = CentralCustomer::query()->find($deskCustomerId);
        if ($customer === null || $customer->central_wallet_id !== $cwid) {
            return ['attempted' => true, 'assigned_refund_ids' => [], 'skipped_reason' => 'customer_wallet_mismatch'];
        }

        $ownerApprovalRef = (string) config(
            'central_wallet.e1_identity_migration.owner_approval_ref',
            'OWNER-CW-E1-IDENTITY-MIGRATION-20261001-001',
        );

        return DB::transaction(function () use (
            $matches,
            $deskCustomerId,
            $cwid,
            $siteCode,
            $localUserId,
            $ownerApprovalRef,
            $correlationId,
            $identity,
        ): array {
            $this->ensureJournalImported();

            $assigned = [];
            foreach ($matches as $row) {
                $refundId = (int) $row['refund_id'];
                $migration = CentralWalletRefundMigration::query()
                    ->where('refund_id', $refundId)
                    ->lockForUpdate()
                    ->first();

                if ($migration === null) {
                    continue;
                }

                if ($migration->cwid !== null && $migration->cwid !== $cwid) {
                    $this->auditAmbiguous($siteCode, $localUserId, $correlationId, 'cwid_conflict_refund_'.$refundId);

                    return ['attempted' => true, 'assigned_refund_ids' => [], 'skipped_reason' => 'identity_ambiguous'];
                }

                if ($migration->desk_customer_id !== null && $migration->desk_customer_id !== $deskCustomerId) {
                    $this->auditAmbiguous($siteCode, $localUserId, $correlationId, 'desk_customer_conflict_refund_'.$refundId);

                    return ['attempted' => true, 'assigned_refund_ids' => [], 'skipped_reason' => 'identity_ambiguous'];
                }

                $metadata = is_array($migration->metadata) ? $migration->metadata : [];
                $e1Meta = is_array($metadata['e1_verification'] ?? null) ? $metadata['e1_verification'] : [];
                if ($migration->status === RefundMigrationStatus::Prepared
                    && ($e1Meta['state'] ?? '') === E1MigrationDestinationState::MigrationDestinationReady->value
                    && $migration->desk_customer_id === $deskCustomerId
                    && $migration->cwid === $cwid) {
                    $assigned[] = $refundId;

                    continue;
                }

                $migration = $this->targetAssignment->assignDirectTarget(
                    refundId: $refundId,
                    deskCustomerId: $deskCustomerId,
                    cwid: $cwid,
                    ownerApprovalRef: $ownerApprovalRef,
                    approvedBy: 'e1_verification_destination:'.$siteCode,
                );

                $metadata = is_array($migration->metadata) ? $migration->metadata : [];
                $metadata['e1_verification'] = [
                    'state' => E1MigrationDestinationState::MigrationDestinationReady->value,
                    'prepared_at' => now()->toIso8601String(),
                    'verification_method' => (string) ($identity['type'] ?? 'verified_email'),
                    'site_code' => $siteCode,
                    'local_user_id' => $localUserId,
                    'evidence' => 'trusted_credential_and_active_account_link',
                    'financial_execution' => false,
                ];
                $migration->metadata = $metadata;
                $migration->save();

                $assigned[] = $refundId;
            }

            if ($assigned !== []) {
                $this->auditEvents->record(
                    eventType: 'e1_verification.destination_prepared',
                    centralWalletId: $cwid,
                    actorType: AuditActorType::Customer,
                    actorId: 'customer:'.$localUserId,
                    correlationId: $correlationId,
                    payload: [
                        'desk_customer_id' => $deskCustomerId,
                        'site_code' => $siteCode,
                        'local_user_id' => $localUserId,
                        'refund_ids' => $assigned,
                        'refund_count' => count($assigned),
                        'owner_approval_ref' => $ownerApprovalRef,
                        'financial_execution' => false,
                    ],
                );
            }

            return ['attempted' => true, 'assigned_refund_ids' => $assigned];
        });
    }

    private function ensureJournalImported(): void
    {
        $batchId = (string) config(
            'central_wallet.e1_identity_migration.batch_id',
            E1IdentityMigrationJournalImportService::BATCH_ID,
        );
        $expected = (int) config('central_wallet.e1_identity_migration.expected_count', E1CohortManifestLoader::EXPECTED_REFUNDS);

        $existing = CentralWalletRefundMigration::query()
            ->where('batch_id', $batchId)
            ->count();

        if ($existing >= $expected) {
            return;
        }

        $this->journalImport->import();
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function hasTrustedCredentialEvidence(string $deskCustomerId, array $identity): bool
    {
        $credentialType = CustomerIdentityCredentialType::tryFrom((string) ($identity['type'] ?? ''));
        if ($credentialType === null) {
            return false;
        }

        if (! in_array($credentialType, [
            CustomerIdentityCredentialType::Google,
            CustomerIdentityCredentialType::VerifiedEmail,
            CustomerIdentityCredentialType::VerifiedMobile,
        ], true)) {
            return false;
        }

        return CentralCustomerIdentityCredential::query()
            ->where('desk_customer_id', $deskCustomerId)
            ->where('credential_type', $credentialType)
            ->exists();
    }

    private function auditAmbiguous(string $siteCode, string $localUserId, ?string $correlationId, string $reason): void
    {
        $this->auditEvents->record(
            eventType: 'e1_verification.ambiguous',
            centralWalletId: null,
            actorType: AuditActorType::Service,
            actorId: 'e1_verification:'.$siteCode,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'reason' => $reason,
            ],
        );
    }
}
