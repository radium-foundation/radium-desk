<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Support\AccountLinkIdentityMetadata;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time identity remediation for verified duplicate customer/CWID pairs.
 * Identity-only — no ledger, migration, or financial mutation.
 */
final class DuplicateIdentityRemediationService
{
    public function __construct(
        private readonly AccountLinkService $accountLinks,
        private readonly AuditEventRecorder $auditEvents,
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function remediateOwnerUser3Duplicate(bool $dryRun = true): array
    {
        $canonicalCustomerId = '46c69a65-7fb9-4d36-948f-32d00475fd0e';
        $canonicalCwid = '50ff2e87-6030-4ae8-b93a-163884db90c5';
        $duplicateCustomerId = 'e6d45399-a3f6-4f93-a07b-902a2a30d918';
        $duplicateCwid = '342bc9c2-d765-4fe9-9914-897bc415ac80';
        $duplicateLinkId = 80;
        $canonicalBoxLinkId = 7;
        $verifiedEmailCredentialId = 74;
        $email = 'ravithelavi@gmail.com';
        $rdinSite = 'rdservice.in';
        $rdinLocalUserId = '3';
        $emailHash = $this->subjectHasher->hashVerifiedEmail($email);

        $this->assertFinanciallyEmpty($duplicateCwid, 'duplicate');
        $this->assertFinanciallyEmpty($canonicalCwid, 'canonical');

        $report = [
            'dry_run' => $dryRun,
            'canonical_customer_id' => $canonicalCustomerId,
            'canonical_cwid' => $canonicalCwid,
            'duplicate_customer_id' => $duplicateCustomerId,
            'duplicate_cwid' => $duplicateCwid,
            'steps' => [],
        ];

        $report['steps'][] = $dryRun ? 'dry_run_preconditions_ok' : 'executing';

        if ($dryRun) {
            return $report;
        }

        return DB::transaction(function () use (
            $canonicalCustomerId,
            $canonicalCwid,
            $duplicateCustomerId,
            $duplicateCwid,
            $duplicateLinkId,
            $canonicalBoxLinkId,
            $verifiedEmailCredentialId,
            $emailHash,
            $rdinSite,
            $rdinLocalUserId,
            $report,
        ): array {
            $credential = CentralCustomerIdentityCredential::query()->lockForUpdate()->find($verifiedEmailCredentialId);
            if ($credential === null) {
                throw new RuntimeException('verified_email_credential_not_found');
            }
            if ($credential->desk_customer_id === $canonicalCustomerId) {
                $report['steps'][] = 'credential_already_on_canonical';
            } else {
                $credential->desk_customer_id = $canonicalCustomerId;
                $credential->save();
                $report['steps'][] = 'credential_reassigned_to_canonical';
            }

            $boxLink = CentralWalletAccountLink::query()->lockForUpdate()->find($canonicalBoxLinkId);
            if ($boxLink === null) {
                throw new RuntimeException('canonical_box_link_not_found');
            }
            $boxLink->metadata = AccountLinkIdentityMetadata::withVerifiedEmailSubjectHash($boxLink->metadata, $emailHash);
            $boxLink->save();
            $report['steps'][] = 'canonical_box_link_metadata_updated';

            $duplicateLink = CentralWalletAccountLink::query()->lockForUpdate()->find($duplicateLinkId);
            if ($duplicateLink === null) {
                throw new RuntimeException('duplicate_link_not_found');
            }
            if ($duplicateLink->status === AccountLinkStatus::Active) {
                $duplicateLink->status = AccountLinkStatus::Revoked;
                $duplicateLink->revoked_at = now();
                $duplicateLink->save();
                $report['steps'][] = 'duplicate_link_revoked';
            } else {
                $report['steps'][] = 'duplicate_link_already_revoked';
            }

            $existingRdinLink = CentralWalletAccountLink::query()
                ->where('site_code', $rdinSite)
                ->where('local_user_id', $rdinLocalUserId)
                ->where('status', AccountLinkStatus::Active)
                ->lockForUpdate()
                ->first();

            if ($existingRdinLink !== null && $existingRdinLink->central_wallet_id === $canonicalCwid) {
                $existingRdinLink->desk_customer_id = $canonicalCustomerId;
                $existingRdinLink->verification_method = 'verified_email';
                $existingRdinLink->metadata = AccountLinkIdentityMetadata::withVerifiedEmailSubjectHash(
                    $existingRdinLink->metadata,
                    $emailHash,
                );
                $existingRdinLink->save();
                $newRdinLinkId = $existingRdinLink->id;
                $report['steps'][] = 'rdin_link_updated_on_canonical';
            } else {
                $newLink = $this->accountLinks->createConfirmedLink(
                    centralWalletId: $canonicalCwid,
                    siteCode: $rdinSite,
                    localUserId: $rdinLocalUserId,
                    createdBy: 'service:identity-remediation',
                    verificationMethod: 'verified_email',
                    actorId: 'service:identity-remediation',
                    correlationId: null,
                    deskCustomerId: $canonicalCustomerId,
                );
                $newLink->metadata = AccountLinkIdentityMetadata::withVerifiedEmailSubjectHash([], $emailHash);
                $newLink->save();
                $newRdinLinkId = $newLink->id;
                $report['steps'][] = 'rdin_link_created_on_canonical';
            }

            $report['rdin_desk_link_id'] = $newRdinLinkId;

            $this->retireDuplicateIdentityShell($duplicateCustomerId, $duplicateCwid, $report);

            $this->auditEvents->record(
                eventType: 'customer_identity.duplicate_remediated',
                centralWalletId: $canonicalCwid,
                actorType: AuditActorType::Service,
                actorId: 'service:identity-remediation',
                correlationId: null,
                payload: [
                    'canonical_customer_id' => $canonicalCustomerId,
                    'retired_duplicate_customer_id' => $duplicateCustomerId,
                    'retired_duplicate_cwid' => $duplicateCwid,
                    'rdin_desk_link_id' => $newRdinLinkId,
                ],
            );

            return $report;
        });
    }

    private function assertFinanciallyEmpty(string $cwid, string $label): void
    {
        $ledgerCount = DB::table('central_wallet_ledger_entries')->where('central_wallet_id', $cwid)->count();
        $reservationCount = DB::table('central_wallet_reservations')->where('central_wallet_id', $cwid)->count();

        if ($ledgerCount > 0 || $reservationCount > 0) {
            throw new RuntimeException($label.'_cwid_not_financially_empty');
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function retireDuplicateIdentityShell(string $duplicateCustomerId, string $duplicateCwid, array &$report): void
    {
        $activeLinks = CentralWalletAccountLink::query()
            ->where('desk_customer_id', $duplicateCustomerId)
            ->where('status', AccountLinkStatus::Active)
            ->count();

        if ($activeLinks > 0) {
            throw new RuntimeException('duplicate_customer_still_has_active_links');
        }

        $remainingCredentials = CentralCustomerIdentityCredential::query()
            ->where('desk_customer_id', $duplicateCustomerId)
            ->count();

        if ($remainingCredentials > 0) {
            throw new RuntimeException('duplicate_customer_still_has_credentials');
        }

        $wallet = CentralWallet::query()->find($duplicateCwid);
        if ($wallet !== null) {
            $wallet->delete();
            $report['steps'][] = 'duplicate_wallet_and_customer_retired';
        }
    }
}
