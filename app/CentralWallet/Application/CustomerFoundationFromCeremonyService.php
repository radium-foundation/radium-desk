<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletCeremonyIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Establishes a Desk Customer record for an existing CWID proven by ceremony identity.
 * Identity foundation only — does not create wallets, credits, or migration entries.
 */
final class CustomerFoundationFromCeremonyService
{
    public function __construct(
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    /**
     * @return array{status: string, desk_customer_id: string, central_wallet_id: string, idempotent_replay: bool}
     */
    public function establishFromCeremony(
        string $siteCode,
        string $localUserId,
        string $actorId,
        ?string $correlationId = null,
    ): array {
        if (! Schema::hasTable('central_customers')) {
            throw new InvalidArgumentException('central_customers_schema_not_deployed');
        }

        $ceremony = CentralWalletCeremonyIdentity::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->first();

        if ($ceremony === null) {
            throw new InvalidArgumentException('ceremony_identity_not_found');
        }

        $cwid = $ceremony->central_wallet_id;
        $wallet = CentralWallet::query()->find($cwid);
        if ($wallet === null) {
            throw new InvalidArgumentException('central_wallet_not_found');
        }

        $existingCustomer = CentralCustomer::query()
            ->where('central_wallet_id', $cwid)
            ->first();

        if ($existingCustomer !== null) {
            $this->attachDeskCustomerToActiveLink($existingCustomer, $siteCode, $localUserId);

            return [
                'status' => 'existing_customer',
                'desk_customer_id' => $existingCustomer->id,
                'central_wallet_id' => $cwid,
                'idempotent_replay' => true,
            ];
        }

        return DB::transaction(function () use (
            $ceremony,
            $siteCode,
            $localUserId,
            $cwid,
            $actorId,
            $correlationId,
        ): array {
            $conflict = CentralCustomer::query()
                ->where('central_wallet_id', $cwid)
                ->lockForUpdate()
                ->first();

            if ($conflict !== null) {
                return [
                    'status' => 'existing_customer',
                    'desk_customer_id' => $conflict->id,
                    'central_wallet_id' => $cwid,
                    'idempotent_replay' => true,
                ];
            }

            $customerId = (string) Str::uuid();

            $customer = CentralCustomer::query()->create([
                'id' => $customerId,
                'central_wallet_id' => $cwid,
                'status' => 'active',
            ]);

            if (
                is_string($ceremony->verified_phone_e164_hash)
                && strlen($ceremony->verified_phone_e164_hash) === 64
            ) {
                CentralCustomerIdentityCredential::query()->create([
                    'desk_customer_id' => $customerId,
                    'credential_type' => CustomerIdentityCredentialType::VerifiedMobile,
                    'provider' => 'ceremony',
                    'subject_hash' => $ceremony->verified_phone_e164_hash,
                    'verified_at' => $ceremony->first_verified_at ?? now(),
                    'metadata' => [
                        'source' => 'ceremony_identity_backfill',
                        'site_code' => $siteCode,
                        'local_user_id' => $localUserId,
                    ],
                ]);
            }

            $this->attachDeskCustomerToActiveLink($customer, $siteCode, $localUserId);

            $this->auditEvents->record(
                eventType: 'customer_identity.established_from_ceremony',
                centralWalletId: $cwid,
                actorType: AuditActorType::Service,
                actorId: $actorId,
                correlationId: $correlationId,
                payload: [
                    'desk_customer_id' => $customerId,
                    'site_code' => $siteCode,
                    'local_user_id' => $localUserId,
                    'ceremony_identity_id' => $ceremony->id,
                ],
            );

            return [
                'status' => 'created_customer',
                'desk_customer_id' => $customerId,
                'central_wallet_id' => $cwid,
                'idempotent_replay' => false,
            ];
        });
    }

    private function attachDeskCustomerToActiveLink(
        CentralCustomer $customer,
        string $siteCode,
        string $localUserId,
    ): void {
        $link = CentralWalletAccountLink::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->where('status', AccountLinkStatus::Active)
            ->first();

        if ($link === null) {
            return;
        }

        if ($link->central_wallet_id !== $customer->central_wallet_id) {
            throw new InvalidArgumentException('active_link_cwid_mismatch');
        }

        if ($link->desk_customer_id === null) {
            $link->desk_customer_id = $customer->id;
            $link->save();
        } elseif ($link->desk_customer_id !== $customer->id) {
            throw new InvalidArgumentException('active_link_customer_conflict');
        }
    }
}
