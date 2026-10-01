<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Cwid;
use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class AccountLinkService
{
    public function __construct(
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createPendingLink(
        string $centralWalletId,
        string $siteCode,
        string $localUserId,
        string $createdBy,
        ?string $verificationMethod = null,
        array $metadata = [],
        ?string $correlationId = null,
    ): CentralWalletAccountLink {
        $this->guardIdentityFields($siteCode, $localUserId, $metadata);

        Cwid::fromString($centralWalletId);

        return DB::transaction(function () use (
            $centralWalletId,
            $siteCode,
            $localUserId,
            $createdBy,
            $verificationMethod,
            $metadata,
            $correlationId,
        ): CentralWalletAccountLink {
            $this->assertNoActiveLink($siteCode, $localUserId);
            $this->assertNoActiveSiteLinkForWallet($centralWalletId, $siteCode);

            $link = CentralWalletAccountLink::query()->create([
                'central_wallet_id' => $centralWalletId,
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'status' => AccountLinkStatus::PendingVerification,
                'verification_method' => $verificationMethod,
                'created_by' => $createdBy,
                'metadata' => $metadata,
            ]);

            $this->auditEvents->record(
                eventType: 'link.pending_created',
                centralWalletId: $centralWalletId,
                actorType: AuditActorType::Service,
                actorId: $createdBy,
                correlationId: $correlationId,
                payload: [
                    'site_code' => $siteCode,
                    'local_user_id' => $localUserId,
                    'link_id' => $link->id,
                ],
            );

            return $link;
        });
    }

    public function createConfirmedLink(
        string $centralWalletId,
        string $siteCode,
        string $localUserId,
        string $createdBy,
        string $verificationMethod,
        string $actorId,
        ?string $correlationId = null,
        ?string $deskCustomerId = null,
    ): CentralWalletAccountLink {
        $this->guardIdentityFields($siteCode, $localUserId, []);

        Cwid::fromString($centralWalletId);

        return DB::transaction(function () use (
            $centralWalletId,
            $siteCode,
            $localUserId,
            $createdBy,
            $verificationMethod,
            $actorId,
            $correlationId,
            $deskCustomerId,
        ): CentralWalletAccountLink {
            $this->assertNoActiveLink($siteCode, $localUserId);
            $this->assertNoActiveSiteLinkForWallet($centralWalletId, $siteCode);

            $link = CentralWalletAccountLink::query()->create([
                'central_wallet_id' => $centralWalletId,
                'desk_customer_id' => $deskCustomerId,
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'status' => AccountLinkStatus::Active,
                'verification_method' => $verificationMethod,
                'created_by' => $createdBy,
                'linked_at' => now(),
                'metadata' => [],
            ]);

            $this->auditEvents->record(
                eventType: 'link.confirmed',
                centralWalletId: $centralWalletId,
                actorType: AuditActorType::Customer,
                actorId: $actorId,
                correlationId: $correlationId,
                payload: [
                    'site_code' => $siteCode,
                    'local_user_id' => $localUserId,
                    'link_id' => $link->id,
                    'verification_method' => $verificationMethod,
                    'via' => 'ceremony_complete',
                ],
            );

            return $link->fresh();
        });
    }

    public function confirmLink(
        CentralWalletAccountLink $link,
        string $verificationMethod,
        string $actorId,
        ?string $correlationId = null,
    ): CentralWalletAccountLink {
        if ($link->status !== AccountLinkStatus::PendingVerification) {
            throw new RuntimeException('Only pending links can be confirmed.');
        }

        return DB::transaction(function () use ($link, $verificationMethod, $actorId, $correlationId): CentralWalletAccountLink {
            $this->assertNoActiveLink($link->site_code, $link->local_user_id);

            $link->status = AccountLinkStatus::Active;
            $link->verification_method = $verificationMethod;
            $link->linked_at = now();
            $link->save();

            $this->auditEvents->record(
                eventType: 'link.confirmed',
                centralWalletId: $link->central_wallet_id,
                actorType: AuditActorType::Customer,
                actorId: $actorId,
                correlationId: $correlationId,
                payload: [
                    'link_id' => $link->id,
                    'site_code' => $link->site_code,
                    'local_user_id' => $link->local_user_id,
                    'verification_method' => $verificationMethod,
                ],
            );

            return $link->fresh();
        });
    }

    public function revokeLink(
        CentralWalletAccountLink $link,
        string $actorId,
        ?string $correlationId = null,
    ): CentralWalletAccountLink {
        if ($link->status === AccountLinkStatus::Revoked) {
            return $link;
        }

        $link->status = AccountLinkStatus::Revoked;
        $link->revoked_at = now();
        $link->save();

        $this->auditEvents->record(
            eventType: 'link.revoked',
            centralWalletId: $link->central_wallet_id,
            actorType: AuditActorType::Operator,
            actorId: $actorId,
            correlationId: $correlationId,
            payload: ['link_id' => $link->id],
        );

        return $link->fresh();
    }

    public function findActiveBySiteUser(string $siteCode, string $localUserId): ?CentralWalletAccountLink
    {
        return CentralWalletAccountLink::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->where('status', AccountLinkStatus::Active)
            ->first();
    }

    public function findByIdForSite(int $linkId, string $siteCode): ?CentralWalletAccountLink
    {
        return CentralWalletAccountLink::query()
            ->whereKey($linkId)
            ->where('site_code', $siteCode)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function guardIdentityFields(string $siteCode, string $localUserId, array $metadata): void
    {
        if ($siteCode === '' || $localUserId === '') {
            throw new InvalidArgumentException('site_code and local_user_id are required.');
        }

        foreach (['email', 'mobile', 'phone'] as $forbiddenPrimaryKey) {
            if (array_key_exists($forbiddenPrimaryKey, $metadata) && ($metadata['forbiddenPrimaryKey'] ?? null) === 'primary_key') {
                throw new InvalidArgumentException('Email and mobile are verification attributes, not wallet primary keys.');
            }
        }
    }

    private function assertNoActiveLink(string $siteCode, string $localUserId): void
    {
        $exists = CentralWalletAccountLink::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->where('status', AccountLinkStatus::Active)
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw new RuntimeException('An active account link already exists for this site user.');
        }
    }

    private function assertNoActiveSiteLinkForWallet(string $centralWalletId, string $siteCode): void
    {
        $exists = CentralWalletAccountLink::query()
            ->where('central_wallet_id', $centralWalletId)
            ->where('site_code', $siteCode)
            ->where('status', AccountLinkStatus::Active)
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw new RuntimeException('An active account link already exists for this wallet on the site.');
        }
    }
}
