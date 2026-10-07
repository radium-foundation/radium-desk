<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Ensures a valid spoke customer resolves to exactly one Desk Central Wallet (CWID).
 * Idempotent: re-resolving the same customer never creates duplicate wallets or links.
 * Spending authorization remains separate (trusted verification required).
 */
final class CentralWalletCustomerIdentityEnsureService
{
    public const VERIFICATION_METHOD_CANONICAL_ACCOUNT = 'canonical_account_identity';

    public const ACTOR_ID_PREFIX = 'customer_identity_ensure';

    public function __construct(
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
        private readonly HistoricalContactIdentityMatchService $contactIdentityMatch,
        private readonly CentralWalletService $wallets,
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function ensure(
        string $siteCode,
        string $localUserId,
        ?string $email,
        ?string $mobile,
        ?string $correlationId = null,
    ): array {
        if (! $this->isEnabled()) {
            return [
                'status' => 503,
                'body' => ['error' => 'customer_identity_ensure_disabled'],
            ];
        }

        $email = strtolower(trim((string) $email));
        $mobileDigits = preg_replace('/\D+/', '', (string) $mobile) ?? '';

        if ($email === '' && strlen($mobileDigits) < 10) {
            return [
                'status' => 422,
                'body' => ['error' => 'contact_data_required'],
            ];
        }

        $existingLink = $this->activeAccountLink($siteCode, $localUserId);
        if ($existingLink !== null) {
            $this->ensureTrustedCredentialsForLinkedCustomer(
                $existingLink,
                $email,
                $mobileDigits,
                $siteCode,
                $localUserId,
                $correlationId,
            );

            return $this->success(
                (string) $existingLink->central_wallet_id,
                'account_link',
                $siteCode,
                $localUserId,
                $correlationId,
                established: false,
            );
        }

        try {
            $credentialCwids = $this->resolveCwidsFromCredentials($email, $mobileDigits);
            if (count($credentialCwids) > 1) {
                return $this->ambiguous($siteCode, $localUserId, $correlationId, 'ambiguous_credential_match');
            }

            if (count($credentialCwids) === 1) {
                $cwid = $credentialCwids[0];
                $this->ensureAccountLink($cwid, $siteCode, $localUserId, $correlationId);

                return $this->success($cwid, 'credential', $siteCode, $localUserId, $correlationId, established: false);
            }

            $contactResult = $this->contactIdentityMatch->resolve(
                $siteCode,
                $localUserId,
                $email !== '' ? $email : null,
                strlen($mobileDigits) >= 10 ? $mobileDigits : null,
                $correlationId,
            );

            if ($contactResult !== null) {
                $contactStatus = (int) ($contactResult['status'] ?? 404);
                if ($contactStatus === 409) {
                    return $contactResult;
                }

                if ($contactStatus === 200) {
                    $cwid = $this->establishCwidForContactIdentity(
                        $email,
                        $mobileDigits,
                        $siteCode,
                        $localUserId,
                        $correlationId,
                    );

                    return $this->success($cwid, 'contact_match', $siteCode, $localUserId, $correlationId, established: true);
                }
            }

            if ($this->hasSpokeAttestedIdentity($email, $mobileDigits, $siteCode, $localUserId)) {
                $cwid = $this->establishCwidForSpokeAccountIdentity(
                    $email,
                    $mobileDigits,
                    $siteCode,
                    $localUserId,
                    $correlationId,
                );

                return $this->success($cwid, 'spoke_account_identity', $siteCode, $localUserId, $correlationId, established: true);
            }

            return $this->notResolved();
        } catch (InvalidArgumentException $exception) {
            if (in_array($exception->getMessage(), ['ambiguous_email_identity', 'ambiguous_mobile_identity', 'credential_conflict', 'cwid_site_link_conflict'], true)) {
                return $this->ambiguous($siteCode, $localUserId, $correlationId, $exception->getMessage());
            }

            return $this->integrationException($siteCode, $localUserId, $correlationId, $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->integrationException($siteCode, $localUserId, $correlationId, $exception->getMessage());
        }
    }

    public function isEnabled(): bool
    {
        return filter_var(
            config('central_wallet.customer_identity_ensure.enabled', true),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    private function hasSpokeAttestedIdentity(
        string $email,
        string $mobileDigits,
        string $siteCode,
        string $localUserId,
    ): bool {
        return trim($siteCode) !== ''
            && trim($localUserId) !== ''
            && (
                ($email !== '' && str_contains($email, '@'))
                || strlen($mobileDigits) >= 10
            );
    }

    private function activeAccountLink(string $siteCode, string $localUserId): ?CentralWalletAccountLink
    {
        return CentralWalletAccountLink::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->where('status', AccountLinkStatus::Active)
            ->first();
    }

    private function ensureAccountLink(
        string $cwid,
        string $siteCode,
        string $localUserId,
        ?string $correlationId,
    ): void {
        $existing = $this->activeAccountLink($siteCode, $localUserId);
        if ($existing !== null) {
            if ((string) $existing->central_wallet_id !== $cwid) {
                throw new InvalidArgumentException('account_link_cwid_conflict');
            }

            return;
        }

        $customer = CentralCustomer::query()->where('central_wallet_id', $cwid)->first();
        if ($customer === null) {
            throw new InvalidArgumentException('central_wallet_customer_missing');
        }

        $this->createActiveLink($customer, $siteCode, $localUserId, $correlationId);
    }

    /**
     * @return list<string>
     */
    private function resolveCwidsFromCredentials(string $email, string $mobileDigits): array
    {
        $cwids = [];

        if ($email !== '' && str_contains($email, '@')) {
            try {
                $emailHash = $this->subjectHasher->hashVerifiedEmail($email);
                $cwids = array_merge($cwids, $this->cwidsForCredentialHash(
                    CustomerIdentityCredentialType::VerifiedEmail,
                    'desk_email',
                    $emailHash,
                ));
            } catch (InvalidArgumentException) {
                // ignore invalid email
            }
        }

        if (strlen($mobileDigits) >= 10) {
            try {
                $e164 = strlen($mobileDigits) === 10 ? '+91'.$mobileDigits : '+'.$mobileDigits;
                $mobileHash = $this->subjectHasher->hashVerifiedMobileE164($e164);
                $cwids = array_merge($cwids, $this->cwidsForCredentialHash(
                    CustomerIdentityCredentialType::VerifiedMobile,
                    'desk_mobile',
                    $mobileHash,
                ));
            } catch (InvalidArgumentException) {
                // ignore invalid mobile for credential lookup
            }
        }

        if ($email !== '' && strlen($mobileDigits) >= 10 && $cwids !== []) {
            $emailCwids = [];
            try {
                $emailHash = $this->subjectHasher->hashVerifiedEmail($email);
                $emailCwids = $this->cwidsForCredentialHash(
                    CustomerIdentityCredentialType::VerifiedEmail,
                    'desk_email',
                    $emailHash,
                );
            } catch (InvalidArgumentException) {
                return [];
            }

            if ($emailCwids !== []) {
                $cwids = array_values(array_intersect($cwids, $emailCwids));
            }
        }

        return array_values(array_unique(array_filter($cwids)));
    }

    /**
     * @return list<string>
     */
    private function cwidsForCredentialHash(
        CustomerIdentityCredentialType $type,
        string $provider,
        string $subjectHash,
    ): array {
        $customerIds = CentralCustomerIdentityCredential::query()
            ->where('credential_type', $type)
            ->where('provider', $provider)
            ->where('subject_hash', $subjectHash)
            ->pluck('desk_customer_id')
            ->unique()
            ->values()
            ->all();

        if ($customerIds === []) {
            return [];
        }

        return CentralCustomer::query()
            ->whereIn('id', $customerIds)
            ->pluck('central_wallet_id')
            ->unique()
            ->values()
            ->all();
    }

    private function establishCwidForContactIdentity(
        string $email,
        string $mobileDigits,
        string $siteCode,
        string $localUserId,
        ?string $correlationId,
    ): string {
        return DB::transaction(function () use ($email, $mobileDigits, $siteCode, $localUserId, $correlationId): string {
            $cwid = $this->resolveOrProvisionCredentialIdentity(
                $email,
                $mobileDigits,
                $siteCode,
                $localUserId,
                'contact_match',
                $correlationId,
            );
            $this->ensureAccountLinkInTransaction($cwid, $siteCode, $localUserId, $correlationId);

            return $cwid;
        });
    }

    private function establishCwidForSpokeAccountIdentity(
        string $email,
        string $mobileDigits,
        string $siteCode,
        string $localUserId,
        ?string $correlationId,
    ): string {
        return DB::transaction(function () use ($email, $mobileDigits, $siteCode, $localUserId, $correlationId): string {
            $cwid = $this->resolveOrProvisionCredentialIdentity(
                $email,
                $mobileDigits,
                $siteCode,
                $localUserId,
                'spoke_account_identity',
                $correlationId,
            );
            $this->ensureAccountLinkInTransaction($cwid, $siteCode, $localUserId, $correlationId);

            return $cwid;
        });
    }

    private function resolveOrProvisionCredentialIdentity(
        string $email,
        string $mobileDigits,
        string $siteCode,
        string $localUserId,
        string $source,
        ?string $correlationId,
    ): string {
        if ($email !== '' && str_contains($email, '@')) {
            $emailHash = $this->subjectHasher->hashVerifiedEmail($email);
            $existing = $this->cwidsForCredentialHash(
                CustomerIdentityCredentialType::VerifiedEmail,
                'desk_email',
                $emailHash,
            );
            if (count($existing) === 1) {
                return $existing[0];
            }
            if (count($existing) > 1) {
                throw new InvalidArgumentException('ambiguous_email_identity');
            }

            return $this->provisionCustomerWithCredential(
                CustomerIdentityCredentialType::VerifiedEmail,
                'desk_email',
                $emailHash,
                $siteCode,
                $localUserId,
                $source,
                $correlationId,
            );
        }

        if (strlen($mobileDigits) >= 10) {
            $e164 = strlen($mobileDigits) === 10 ? '+91'.$mobileDigits : '+'.$mobileDigits;
            $mobileHash = $this->subjectHasher->hashVerifiedMobileE164($e164);
            $existing = $this->cwidsForCredentialHash(
                CustomerIdentityCredentialType::VerifiedMobile,
                'desk_mobile',
                $mobileHash,
            );
            if (count($existing) === 1) {
                return $existing[0];
            }
            if (count($existing) > 1) {
                throw new InvalidArgumentException('ambiguous_mobile_identity');
            }

            return $this->provisionCustomerWithCredential(
                CustomerIdentityCredentialType::VerifiedMobile,
                'desk_mobile',
                $mobileHash,
                $siteCode,
                $localUserId,
                $source,
                $correlationId,
            );
        }

        throw new InvalidArgumentException('contact_data_required');
    }

    private function provisionCustomerWithCredential(
        CustomerIdentityCredentialType $type,
        string $provider,
        string $subjectHash,
        string $siteCode,
        string $localUserId,
        string $source,
        ?string $correlationId,
    ): string {
        $wallet = $this->wallets->create($correlationId);
        $customerId = (string) Str::uuid();

        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $wallet->id,
            'status' => 'active',
        ]);

        try {
            CentralCustomerIdentityCredential::query()->create([
                'desk_customer_id' => $customerId,
                'credential_type' => $type,
                'provider' => $provider,
                'subject_hash' => $subjectHash,
                'verified_at' => now(),
                'metadata' => [
                    'source' => $source,
                    'site_code' => $siteCode,
                    'local_user_id' => $localUserId,
                ],
            ]);
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'central_customer_credentials_subject_uq')) {
                throw new InvalidArgumentException('credential_conflict');
            }

            throw $exception;
        }

        $this->auditEvents->record(
            eventType: 'customer_identity.ensure_established',
            centralWalletId: $wallet->id,
            actorType: AuditActorType::Service,
            actorId: self::ACTOR_ID_PREFIX.':'.$siteCode,
            correlationId: $correlationId,
            payload: [
                'desk_customer_id' => $customerId,
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'credential_type' => $type->value,
                'source' => $source,
            ],
        );

        return $wallet->id;
    }

    private function ensureAccountLinkInTransaction(
        string $cwid,
        string $siteCode,
        string $localUserId,
        ?string $correlationId,
    ): void {
        $existing = CentralWalletAccountLink::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->where('status', AccountLinkStatus::Active)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if ((string) $existing->central_wallet_id !== $cwid) {
                throw new InvalidArgumentException('account_link_cwid_conflict');
            }

            return;
        }

        $customer = CentralCustomer::query()->where('central_wallet_id', $cwid)->lockForUpdate()->first();
        if ($customer === null) {
            throw new InvalidArgumentException('central_wallet_customer_missing');
        }

        $this->createActiveLink($customer, $siteCode, $localUserId, $correlationId);
    }

    private function createActiveLink(
        CentralCustomer $customer,
        string $siteCode,
        string $localUserId,
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
            'verification_method' => self::VERIFICATION_METHOD_CANONICAL_ACCOUNT,
            'created_by' => self::ACTOR_ID_PREFIX.':'.$siteCode,
            'linked_at' => now(),
            'metadata' => ['source' => 'customer_identity_ensure'],
        ]);
    }

    private function ensureTrustedCredentialsForLinkedCustomer(
        CentralWalletAccountLink $link,
        string $email,
        string $mobileDigits,
        string $siteCode,
        string $localUserId,
        ?string $correlationId,
    ): void {
        if (! $this->hasSpokeAttestedIdentity($email, $mobileDigits, $siteCode, $localUserId)) {
            return;
        }

        $deskCustomerId = trim((string) $link->desk_customer_id);
        if ($deskCustomerId === '') {
            return;
        }

        $customer = CentralCustomer::query()->find($deskCustomerId);
        if ($customer === null || trim((string) $customer->central_wallet_id) === '') {
            return;
        }

        $centralWalletId = (string) $customer->central_wallet_id;
        if ((string) $link->central_wallet_id !== $centralWalletId) {
            return;
        }

        $ownerIds = CentralCustomer::query()
            ->where('central_wallet_id', $centralWalletId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id);

        if ($ownerIds->count() !== 1 || $ownerIds->first() !== $deskCustomerId) {
            return;
        }

        if ($email !== '' && str_contains($email, '@')) {
            $this->ensureEmailCredentialForCustomer(
                $customer,
                $email,
                $siteCode,
                $localUserId,
                $correlationId,
            );
        }

        if (strlen($mobileDigits) >= 10) {
            $this->ensureMobileCredentialForCustomer(
                $customer,
                $mobileDigits,
                $siteCode,
                $localUserId,
                $correlationId,
            );
        }
    }

    private function ensureEmailCredentialForCustomer(
        CentralCustomer $customer,
        string $email,
        string $siteCode,
        string $localUserId,
        ?string $correlationId,
    ): void {
        try {
            $subjectHash = $this->subjectHasher->hashVerifiedEmail($email);
        } catch (InvalidArgumentException) {
            return;
        }

        if ($this->verifiedCredentialExistsForCustomer(
            $customer,
            CustomerIdentityCredentialType::VerifiedEmail,
            'desk_email',
            $subjectHash,
        )) {
            return;
        }

        if ($this->verifiedCredentialExistsForAnotherCustomer(
            $customer,
            CustomerIdentityCredentialType::VerifiedEmail,
            'desk_email',
            $subjectHash,
        )) {
            return;
        }

        $this->createVerifiedCredential(
            $customer,
            CustomerIdentityCredentialType::VerifiedEmail,
            'desk_email',
            $subjectHash,
            $siteCode,
            $localUserId,
            'linked_account_credential_ensure',
            $correlationId,
        );
    }

    private function ensureMobileCredentialForCustomer(
        CentralCustomer $customer,
        string $mobileDigits,
        string $siteCode,
        string $localUserId,
        ?string $correlationId,
    ): void {
        try {
            $e164 = strlen($mobileDigits) === 10 ? '+91'.$mobileDigits : '+'.$mobileDigits;
            $subjectHash = $this->subjectHasher->hashVerifiedMobileE164($e164);
        } catch (InvalidArgumentException) {
            return;
        }

        if ($this->verifiedCredentialExistsForCustomer(
            $customer,
            CustomerIdentityCredentialType::VerifiedMobile,
            'desk_mobile',
            $subjectHash,
        )) {
            return;
        }

        if ($this->verifiedCredentialExistsForAnotherCustomer(
            $customer,
            CustomerIdentityCredentialType::VerifiedMobile,
            'desk_mobile',
            $subjectHash,
        )) {
            return;
        }

        $this->createVerifiedCredential(
            $customer,
            CustomerIdentityCredentialType::VerifiedMobile,
            'desk_mobile',
            $subjectHash,
            $siteCode,
            $localUserId,
            'linked_account_credential_ensure',
            $correlationId,
        );
    }

    private function verifiedCredentialExistsForCustomer(
        CentralCustomer $customer,
        CustomerIdentityCredentialType $type,
        string $provider,
        string $subjectHash,
    ): bool {
        return CentralCustomerIdentityCredential::query()
            ->where('desk_customer_id', $customer->id)
            ->where('credential_type', $type)
            ->where('provider', $provider)
            ->where('subject_hash', $subjectHash)
            ->whereNotNull('verified_at')
            ->exists();
    }

    private function verifiedCredentialExistsForAnotherCustomer(
        CentralCustomer $customer,
        CustomerIdentityCredentialType $type,
        string $provider,
        string $subjectHash,
    ): bool {
        return CentralCustomerIdentityCredential::query()
            ->where('desk_customer_id', '!=', $customer->id)
            ->where('credential_type', $type)
            ->where('provider', $provider)
            ->where('subject_hash', $subjectHash)
            ->whereNotNull('verified_at')
            ->exists();
    }

    private function createVerifiedCredential(
        CentralCustomer $customer,
        CustomerIdentityCredentialType $type,
        string $provider,
        string $subjectHash,
        string $siteCode,
        string $localUserId,
        string $source,
        ?string $correlationId,
    ): void {
        try {
            CentralCustomerIdentityCredential::query()->create([
                'desk_customer_id' => $customer->id,
                'credential_type' => $type,
                'provider' => $provider,
                'subject_hash' => $subjectHash,
                'verified_at' => now(),
                'metadata' => [
                    'source' => $source,
                    'site_code' => $siteCode,
                    'local_user_id' => $localUserId,
                ],
            ]);
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'central_customer_credentials_subject_uq')) {
                return;
            }

            throw $exception;
        }

        $this->auditEvents->record(
            eventType: 'customer_identity.ensure_linked_credential',
            centralWalletId: (string) $customer->central_wallet_id,
            actorType: AuditActorType::Service,
            actorId: self::ACTOR_ID_PREFIX.':'.$siteCode,
            correlationId: $correlationId,
            payload: [
                'desk_customer_id' => (string) $customer->id,
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'credential_type' => $type->value,
                'source' => $source,
            ],
        );
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function success(
        string $cwid,
        string $matchBasis,
        string $siteCode,
        string $localUserId,
        ?string $correlationId,
        bool $established,
    ): array {
        $this->auditEvents->record(
            eventType: 'customer_identity.ensure_resolved',
            centralWalletId: $cwid,
            actorType: AuditActorType::Service,
            actorId: self::ACTOR_ID_PREFIX.':'.$siteCode,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'match_basis' => $matchBasis,
                'established' => $established,
            ],
        );

        return [
            'status' => 200,
            'body' => [
                'central_wallet_id' => $cwid,
                'identity_state' => $established ? 'established' : 'resolved',
                'verification_required' => false,
                'refund_destination_authorized' => true,
                'match_basis' => $matchBasis,
            ],
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function notResolved(): array
    {
        return [
            'status' => 404,
            'body' => [
                'error' => 'identity_unresolved',
                'identity_state' => 'unresolved',
            ],
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function ambiguous(
        string $siteCode,
        string $localUserId,
        ?string $correlationId,
        string $reason,
    ): array {
        $this->auditEvents->record(
            eventType: 'customer_identity.ensure_ambiguous',
            centralWalletId: null,
            actorType: AuditActorType::Service,
            actorId: self::ACTOR_ID_PREFIX.':'.$siteCode,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'reason' => $reason,
            ],
        );

        return [
            'status' => 409,
            'body' => [
                'error' => 'identity_ambiguous',
                'identity_state' => 'ambiguous',
                'reason' => $reason,
            ],
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function integrationException(
        string $siteCode,
        string $localUserId,
        ?string $correlationId,
        string $reason,
    ): array {
        $this->auditEvents->record(
            eventType: 'customer_identity.ensure_integration_exception',
            centralWalletId: null,
            actorType: AuditActorType::Service,
            actorId: self::ACTOR_ID_PREFIX.':'.$siteCode,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'reason' => $reason,
            ],
        );

        return [
            'status' => 503,
            'body' => [
                'error' => 'identity_integration_exception',
                'identity_state' => 'integration_exception',
                'message' => 'A valid customer identity exists but Central Wallet identity could not be established safely. Manual investigation is required.',
                'reason' => $reason,
            ],
        ];
    }
}
