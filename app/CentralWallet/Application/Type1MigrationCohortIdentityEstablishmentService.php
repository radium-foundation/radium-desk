<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Owner-authorized server-side identity for the immutable 50-customer TYPE-1 cohort.
 * Does NOT require M2 Connect Wallet OTP. Does NOT write ledger entries.
 */
final class Type1MigrationCohortIdentityEstablishmentService
{
    public function __construct(
        private readonly Type1MigrationCohortManifestLoader $manifestLoader,
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
        private readonly MigrationControlledCwidProvisionService $trustedProvisioner,
        private readonly CentralWalletService $wallets,
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     identity_class: string,
     *     migration_identity_state: string,
     *     desk_customer_id?: string,
     *     central_wallet_id?: string,
     *     idempotent_replay: bool,
     *     refund_ids: list<int>,
     *     blocked_reason?: string
     * }
     */
    public function establish(
        string $siteCode,
        string $localUserId,
        string $actorId,
        ?string $correlationId = null,
        ?array $trustedIdentity = null,
    ): array {
        if (! filter_var(config('central_wallet.type1_migration_cohort.identity_establishment_enabled'), FILTER_VALIDATE_BOOLEAN)) {
            throw new InvalidArgumentException('type1_migration_cohort_identity_disabled');
        }

        $manifest = $this->manifestLoader->load();
        $customer = $this->manifestLoader->findCustomer($manifest, $siteCode, $localUserId);
        if ($customer === null) {
            throw new InvalidArgumentException('not_in_type1_migration_cohort');
        }

        $refundIds = array_values(array_map('intval', $customer['refund_ids'] ?? []));
        $identityClass = $this->classifyIdentity($trustedIdentity);

        if ($identityClass === 'D' || $identityClass === 'E') {
            return [
                'status' => 'identity_blocked',
                'identity_class' => $identityClass,
                'migration_identity_state' => 'IDENTITY_BLOCKED',
                'idempotent_replay' => false,
                'refund_ids' => $refundIds,
                'blocked_reason' => 'insufficient_or_ambiguous_identity',
            ];
        }

        if ($identityClass === 'A' || $identityClass === 'B') {
            $result = $this->trustedProvisioner->provision(
                siteCode: $siteCode,
                localUserId: $localUserId,
                identity: $trustedIdentity ?? [],
                evidence: [
                    'cohort_id' => Type1MigrationCohortManifestLoader::COHORT_ID,
                    'refund_ids' => $refundIds,
                    'identity_class' => $identityClass,
                ],
                actorId: $actorId,
                correlationId: $correlationId,
            );

            return [
                'status' => $result['status'],
                'identity_class' => $identityClass,
                'migration_identity_state' => 'IDENTITY_ESTABLISHED',
                'desk_customer_id' => $result['desk_customer_id'],
                'central_wallet_id' => $result['central_wallet_id'],
                'idempotent_replay' => (bool) ($result['idempotent_replay'] ?? false),
                'refund_ids' => $refundIds,
            ];
        }

        return $this->establishFromCohortAnchor(
            manifest: $manifest,
            customer: $customer,
            siteCode: $siteCode,
            localUserId: $localUserId,
            refundIds: $refundIds,
            actorId: $actorId,
            correlationId: $correlationId,
        );
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $customer
     * @param  list<int>  $refundIds
     * @return array<string, mixed>
     */
    private function establishFromCohortAnchor(
        array $manifest,
        array $customer,
        string $siteCode,
        string $localUserId,
        array $refundIds,
        string $actorId,
        ?string $correlationId,
    ): array {
        if (! Schema::hasTable('central_customers')) {
            throw new InvalidArgumentException('central_customers_schema_not_deployed');
        }

        $existingLink = CentralWalletAccountLink::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->where('status', AccountLinkStatus::Active)
            ->first();

        if ($existingLink !== null) {
            $deskCustomer = CentralCustomer::query()
                ->where('central_wallet_id', $existingLink->central_wallet_id)
                ->first();

            if ($deskCustomer === null) {
                throw new InvalidArgumentException('active_link_missing_desk_customer');
            }

            if ($existingLink->desk_customer_id === null) {
                $existingLink->desk_customer_id = $deskCustomer->id;
                $existingLink->save();
            }

            $this->assertNoFinancialMutation();

            return [
                'status' => 'existing_link',
                'identity_class' => 'C',
                'migration_identity_state' => 'IDENTITY_ESTABLISHED',
                'desk_customer_id' => $deskCustomer->id,
                'central_wallet_id' => $existingLink->central_wallet_id,
                'idempotent_replay' => true,
                'refund_ids' => $refundIds,
            ];
        }

        $cohortId = (string) ($manifest['cohort_id'] ?? Type1MigrationCohortManifestLoader::COHORT_ID);
        $subjectHash = $this->subjectHasher->hashMigrationCohortAnchor($cohortId, $siteCode, $localUserId, $refundIds);

        $matched = CentralCustomerIdentityCredential::query()
            ->where('credential_type', CustomerIdentityCredentialType::MigrationCohortAnchor)
            ->where('provider', 'owner_migration_cohort')
            ->where('subject_hash', $subjectHash)
            ->get();

        if ($matched->pluck('desk_customer_id')->unique()->count() > 1) {
            return [
                'status' => 'identity_blocked',
                'identity_class' => 'D',
                'migration_identity_state' => 'IDENTITY_BLOCKED',
                'idempotent_replay' => false,
                'refund_ids' => $refundIds,
                'blocked_reason' => 'credential_ambiguous',
            ];
        }

        $matchedCredential = $matched->first();
        if ($matchedCredential !== null) {
            $deskCustomer = CentralCustomer::query()->find($matchedCredential->desk_customer_id);
            if ($deskCustomer === null) {
                throw new InvalidArgumentException('credential_orphan');
            }

            $link = $this->createActiveLink($deskCustomer, $siteCode, $localUserId, $actorId, $refundIds, $customer);
            $this->assertNoFinancialMutation();

            return [
                'status' => 'resolved_existing_customer',
                'identity_class' => 'C',
                'migration_identity_state' => 'IDENTITY_ESTABLISHED',
                'desk_customer_id' => $deskCustomer->id,
                'central_wallet_id' => $deskCustomer->central_wallet_id,
                'idempotent_replay' => false,
                'refund_ids' => $refundIds,
                'link_id' => $link->id,
            ];
        }

        return DB::transaction(function () use (
            $siteCode,
            $localUserId,
            $refundIds,
            $subjectHash,
            $customer,
            $cohortId,
            $actorId,
            $correlationId,
        ): array {
            $wallet = $this->wallets->create($correlationId);
            $customerId = (string) Str::uuid();

            $deskCustomer = CentralCustomer::query()->create([
                'id' => $customerId,
                'central_wallet_id' => $wallet->id,
                'status' => 'active',
            ]);

            try {
                CentralCustomerIdentityCredential::query()->create([
                    'desk_customer_id' => $customerId,
                    'credential_type' => CustomerIdentityCredentialType::MigrationCohortAnchor,
                    'provider' => 'owner_migration_cohort',
                    'subject_hash' => $subjectHash,
                    'verified_at' => now(),
                    'metadata' => [
                        'source' => 'type1_migration_cohort_identity',
                        'cohort_id' => $cohortId,
                        'site_code' => $siteCode,
                        'local_user_id' => $localUserId,
                        'refund_ids' => $refundIds,
                        'customer_group_id' => $customer['customer_group_id'] ?? null,
                        'note' => 'Owner-authorized cohort anchor; not raw email/mobile trust',
                    ],
                ]);
            } catch (QueryException $exception) {
                if (str_contains($exception->getMessage(), 'central_customer_credentials_subject_uq')) {
                    throw new InvalidArgumentException('credential_conflict');
                }

                throw $exception;
            }

            $link = $this->createActiveLink($deskCustomer, $siteCode, $localUserId, $actorId, $refundIds, $customer);

            $this->auditEvents->record(
                eventType: 'customer_identity.type1_migration_cohort_established',
                centralWalletId: $wallet->id,
                actorType: AuditActorType::Service,
                actorId: $actorId,
                correlationId: $correlationId,
                payload: [
                    'desk_customer_id' => $customerId,
                    'site_code' => $siteCode,
                    'local_user_id' => $localUserId,
                    'cohort_id' => $cohortId,
                    'refund_ids' => $refundIds,
                    'link_id' => $link->id,
                    'identity_class' => 'C',
                ],
            );

            $this->assertNoFinancialMutation();

            return [
                'status' => 'created_customer',
                'identity_class' => 'C',
                'migration_identity_state' => 'IDENTITY_ESTABLISHED',
                'desk_customer_id' => $customerId,
                'central_wallet_id' => $wallet->id,
                'idempotent_replay' => false,
                'refund_ids' => $refundIds,
                'link_id' => $link->id,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $customer
     * @param  list<int>  $refundIds
     */
    private function createActiveLink(
        CentralCustomer $deskCustomer,
        string $siteCode,
        string $localUserId,
        string $actorId,
        array $refundIds,
        array $customer,
    ): CentralWalletAccountLink {
        $conflictingWalletLink = CentralWalletAccountLink::query()
            ->where('central_wallet_id', $deskCustomer->central_wallet_id)
            ->where('site_code', $siteCode)
            ->where('status', AccountLinkStatus::Active)
            ->where('local_user_id', '!=', $localUserId)
            ->exists();

        if ($conflictingWalletLink) {
            throw new InvalidArgumentException('cwid_site_link_conflict');
        }

        $existing = CentralWalletAccountLink::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->where('status', AccountLinkStatus::Active)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $deskCustomer->central_wallet_id,
            'desk_customer_id' => $deskCustomer->id,
            'site_code' => $siteCode,
            'local_user_id' => $localUserId,
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'owner_migration_cohort',
            'created_by' => $actorId,
            'linked_at' => now(),
            'metadata' => [
                'source' => 'type1_migration_cohort_identity',
                'refund_ids' => $refundIds,
                'customer_group_id' => $customer['customer_group_id'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $trustedIdentity
     */
    private function classifyIdentity(?array $trustedIdentity): string
    {
        if ($trustedIdentity === null) {
            return 'C';
        }

        $type = (string) ($trustedIdentity['type'] ?? '');
        if ($type === CustomerIdentityCredentialType::Google->value) {
            return 'A';
        }
        if ($type === CustomerIdentityCredentialType::VerifiedEmail->value) {
            return 'B';
        }

        return 'E';
    }

    private function assertNoFinancialMutation(): void
    {
        if (CentralWalletLedgerEntry::query()->exists()) {
            throw new InvalidArgumentException('financial_mutation_detected');
        }
    }
}
