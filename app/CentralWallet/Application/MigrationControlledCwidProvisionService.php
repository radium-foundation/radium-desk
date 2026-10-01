<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletCeremonyIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Controlled migration-prep CWID provisioning from trusted identity evidence only.
 * Creates wallet + desk customer + credential + active account link. No ledger writes.
 */
final class MigrationControlledCwidProvisionService
{
    public function __construct(
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
        private readonly CentralWalletService $wallets,
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $evidence
     * @return array{status: string, desk_customer_id: string, central_wallet_id: string, idempotent_replay: bool, identity_type: string}
     */
    public function provision(
        string $siteCode,
        string $localUserId,
        array $identity,
        array $evidence,
        string $actorId,
        ?string $correlationId = null,
    ): array {
        if (! Schema::hasTable('central_customers')) {
            throw new InvalidArgumentException('central_customers_schema_not_deployed');
        }

        $credential = $this->subjectHasher->fromTrustedIdentityPayload($identity);

        $existingLink = CentralWalletAccountLink::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->where('status', AccountLinkStatus::Active)
            ->first();

        if ($existingLink !== null) {
            $customer = CentralCustomer::query()
                ->where('central_wallet_id', $existingLink->central_wallet_id)
                ->first();

            if ($customer === null) {
                throw new InvalidArgumentException('active_link_missing_desk_customer');
            }

            if ($existingLink->desk_customer_id === null) {
                $existingLink->desk_customer_id = $customer->id;
                $existingLink->save();
            }

            return [
                'status' => 'existing_link',
                'desk_customer_id' => $customer->id,
                'central_wallet_id' => $existingLink->central_wallet_id,
                'idempotent_replay' => true,
                'identity_type' => $credential['credential_type']->value,
            ];
        }

        $ceremony = CentralWalletCeremonyIdentity::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->first();

        if ($ceremony !== null) {
            $customer = CentralCustomer::query()
                ->where('central_wallet_id', $ceremony->central_wallet_id)
                ->first();

            if ($customer !== null) {
                return [
                    'status' => 'existing_ceremony_customer',
                    'desk_customer_id' => $customer->id,
                    'central_wallet_id' => $ceremony->central_wallet_id,
                    'idempotent_replay' => true,
                    'identity_type' => $credential['credential_type']->value,
                ];
            }
        }

        $matchedCredentials = CentralCustomerIdentityCredential::query()
            ->where('credential_type', $credential['credential_type'])
            ->where('provider', $credential['provider'])
            ->where('subject_hash', $credential['subject_hash'])
            ->get();

        if ($matchedCredentials->pluck('desk_customer_id')->unique()->count() > 1) {
            throw new InvalidArgumentException('credential_ambiguous');
        }

        $matchedCredential = $matchedCredentials->first();

        if ($matchedCredential !== null) {
            $customer = CentralCustomer::query()->find($matchedCredential->desk_customer_id);
            if ($customer === null) {
                throw new InvalidArgumentException('credential_orphan');
            }

            $link = $this->createActiveLink(
                $customer,
                $siteCode,
                $localUserId,
                $this->verificationMethodForCredential($credential['credential_type']),
                $actorId,
                $correlationId,
            );

            return [
                'status' => 'resolved_existing_customer',
                'desk_customer_id' => $customer->id,
                'central_wallet_id' => $customer->central_wallet_id,
                'idempotent_replay' => false,
                'identity_type' => $credential['credential_type']->value,
                'link_id' => $link->id,
            ];
        }

        return DB::transaction(function () use (
            $siteCode,
            $localUserId,
            $credential,
            $evidence,
            $actorId,
            $correlationId,
        ): array {
            $wallet = $this->wallets->create($correlationId);
            $customerId = (string) Str::uuid();

            $customer = CentralCustomer::query()->create([
                'id' => $customerId,
                'central_wallet_id' => $wallet->id,
                'status' => 'active',
            ]);

            try {
                CentralCustomerIdentityCredential::query()->create([
                    'desk_customer_id' => $customerId,
                    'credential_type' => $credential['credential_type'],
                    'provider' => $credential['provider'],
                    'subject_hash' => $credential['subject_hash'],
                    'verified_at' => now(),
                    'metadata' => [
                        'source' => 'migration_controlled_provision',
                        'site_code' => $siteCode,
                        'local_user_id' => $localUserId,
                        'evidence' => $evidence,
                    ],
                ]);
            } catch (QueryException $exception) {
                if (str_contains($exception->getMessage(), 'central_customer_credentials_subject_uq')) {
                    throw new InvalidArgumentException('credential_conflict');
                }

                throw $exception;
            }

            $link = $this->createActiveLink(
                $customer,
                $siteCode,
                $localUserId,
                $this->verificationMethodForCredential($credential['credential_type']),
                $actorId,
                $correlationId,
            );

            $this->auditEvents->record(
                eventType: 'customer_identity.migration_controlled_provision',
                centralWalletId: $wallet->id,
                actorType: AuditActorType::Service,
                actorId: $actorId,
                correlationId: $correlationId,
                payload: [
                    'desk_customer_id' => $customerId,
                    'site_code' => $siteCode,
                    'local_user_id' => $localUserId,
                    'credential_type' => $credential['credential_type']->value,
                    'evidence' => $evidence,
                    'link_id' => $link->id,
                ],
            );

            return [
                'status' => 'created_customer',
                'desk_customer_id' => $customerId,
                'central_wallet_id' => $wallet->id,
                'idempotent_replay' => false,
                'identity_type' => $credential['credential_type']->value,
                'link_id' => $link->id,
            ];
        });
    }

    private function createActiveLink(
        CentralCustomer $customer,
        string $siteCode,
        string $localUserId,
        string $verificationMethod,
        string $actorId,
        ?string $correlationId,
    ): CentralWalletAccountLink {
        $conflictingWalletLink = CentralWalletAccountLink::query()
            ->where('central_wallet_id', $customer->central_wallet_id)
            ->where('site_code', $siteCode)
            ->where('status', AccountLinkStatus::Active)
            ->where('local_user_id', '!=', $localUserId)
            ->exists();

        if ($conflictingWalletLink) {
            throw new InvalidArgumentException('cwid_site_link_conflict');
        }

        return CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $customer->central_wallet_id,
            'desk_customer_id' => $customer->id,
            'site_code' => $siteCode,
            'local_user_id' => $localUserId,
            'status' => AccountLinkStatus::Active,
            'verification_method' => $verificationMethod,
            'created_by' => $actorId,
            'linked_at' => now(),
            'metadata' => ['source' => 'migration_controlled_provision'],
        ]);
    }

    private function verificationMethodForCredential(CustomerIdentityCredentialType $type): string
    {
        return match ($type) {
            CustomerIdentityCredentialType::Google => 'trusted_google',
            CustomerIdentityCredentialType::VerifiedEmail => 'verified_email',
            CustomerIdentityCredentialType::VerifiedMobile => 'verified_mobile',
        };
    }
}
