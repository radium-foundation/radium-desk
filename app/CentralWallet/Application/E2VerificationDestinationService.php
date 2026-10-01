<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class E2VerificationDestinationService
{
    public function __construct(
        private readonly E2CohortManifestLoader $cohortManifestLoader,
        private readonly E2HistoricalSettlementManifestLoader $settlementManifestLoader,
        private readonly E2HistoricalSettlementJournalImportService $journalImport,
        private readonly RefundMigrationTargetAssignmentService $targetAssignment,
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
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
        if (! (bool) config('central_wallet.e2_historical_settlement.verification_enabled', false)) {
            return ['attempted' => false, 'assigned_refund_ids' => [], 'skipped_reason' => 'e2_verification_disabled'];
        }

        $emailHashes = $this->trustedEmailHashesFromIdentity($identity, $deskCustomerId);
        if ($emailHashes === []) {
            return ['attempted' => true, 'assigned_refund_ids' => [], 'skipped_reason' => 'no_trusted_email_evidence'];
        }

        if (count($emailHashes) > 1) {
            $this->auditAmbiguous($siteCode, $localUserId, $correlationId, 'multiple_trusted_email_hashes');

            return ['attempted' => true, 'assigned_refund_ids' => [], 'skipped_reason' => 'identity_ambiguous'];
        }

        $emailHash = $emailHashes[0];

        try {
            $cohortManifest = $this->cohortManifestLoader->load();
        } catch (InvalidArgumentException) {
            return ['attempted' => true, 'assigned_refund_ids' => [], 'skipped_reason' => 'cohort_manifest_unavailable'];
        }

        $matches = $this->cohortManifestLoader->findBySiteEmailHash($cohortManifest, $siteCode, $emailHash);
        if ($matches === []) {
            return ['attempted' => true, 'assigned_refund_ids' => [], 'skipped_reason' => 'no_e2_cohort_match'];
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

        if (! $this->hasTrustedEmailEvidence($deskCustomerId, $emailHash, $identity)) {
            return ['attempted' => true, 'assigned_refund_ids' => [], 'skipped_reason' => 'trusted_email_credential_missing'];
        }

        $ownerApprovalRef = (string) config(
            'central_wallet.e2_historical_settlement.owner_approval_ref',
            E2HistoricalSettlementManifestLoader::DEFAULT_OWNER_APPROVAL_REF,
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
            $emailHash,
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

                if ($migration->status === RefundMigrationStatus::Prepared
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
                    approvedBy: 'e2_verification_destination:'.$siteCode,
                );

                $metadata = is_array($migration->metadata) ? $migration->metadata : [];
                $metadata['e2_verification'] = [
                    'state' => 'SETTLEMENT_DESTINATION_READY',
                    'prepared_at' => now()->toIso8601String(),
                    'verification_method' => (string) ($identity['type'] ?? 'verified_email'),
                    'site_code' => $siteCode,
                    'local_user_id' => $localUserId,
                    'order_email_hash' => $emailHash,
                    'source_wallet_provenance' => 'unavailable_not_reconstructed',
                    'settlement_note' => 'Lane 4 destination only; NOT evidence of original 2026 spoke wallet credit',
                ];
                $migration->metadata = $metadata;
                $migration->save();

                $assigned[] = $refundId;
            }

            if ($assigned !== []) {
                $this->auditEvents->record(
                    eventType: 'e2_verification.destination_prepared',
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
                        'lane4_execution' => false,
                    ],
                );
            }

            return ['attempted' => true, 'assigned_refund_ids' => $assigned];
        });
    }

    private function ensureJournalImported(): void
    {
        $batchId = E2HistoricalSettlementManifestLoader::BATCH_ID;
        $existing = CentralWalletRefundMigration::query()
            ->where('batch_id', $batchId)
            ->count();

        if ($existing >= E2HistoricalSettlementManifestLoader::EXPECTED_COUNT) {
            return;
        }

        $this->journalImport->import();
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return list<string>
     */
    private function trustedEmailHashesFromIdentity(array $identity, string $deskCustomerId): array
    {
        $hashes = [];
        $type = (string) ($identity['type'] ?? '');

        if ($type === CustomerIdentityCredentialType::VerifiedEmail->value) {
            try {
                $hashes[] = $this->subjectHasher->hashVerifiedEmail((string) ($identity['email'] ?? ''));
            } catch (InvalidArgumentException) {
                return [];
            }
        }

        if ($type === CustomerIdentityCredentialType::Google->value) {
            $email = trim((string) ($identity['email'] ?? ''));
            if ($email !== '' && filter_var(strtolower($email), FILTER_VALIDATE_EMAIL)) {
                try {
                    $hashes[] = $this->subjectHasher->hashVerifiedEmail($email);
                } catch (InvalidArgumentException) {
                    return [];
                }
            }
        }

        if ($hashes === []) {
            $hashes = CentralCustomerIdentityCredential::query()
                ->where('desk_customer_id', $deskCustomerId)
                ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
                ->pluck('subject_hash')
                ->unique()
                ->values()
                ->all();
        }

        return array_values(array_unique($hashes));
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function hasTrustedEmailEvidence(string $deskCustomerId, string $emailHash, array $identity): bool
    {
        $hasCredential = CentralCustomerIdentityCredential::query()
            ->where('desk_customer_id', $deskCustomerId)
            ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
            ->where('subject_hash', $emailHash)
            ->exists();

        if ($hasCredential) {
            return true;
        }

        $type = (string) ($identity['type'] ?? '');
        if ($type !== CustomerIdentityCredentialType::Google->value) {
            return false;
        }

        $email = trim((string) ($identity['email'] ?? ''));
        if ($email === '' || ! filter_var(strtolower($email), FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            return $this->subjectHasher->hashVerifiedEmail($email) === $emailHash;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function auditAmbiguous(string $siteCode, string $localUserId, ?string $correlationId, string $reason): void
    {
        $this->auditEvents->record(
            eventType: 'e2_verification.ambiguous',
            centralWalletId: null,
            actorType: AuditActorType::Service,
            actorId: 'e2_verification:'.$siteCode,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'reason' => $reason,
            ],
        );
    }
}
