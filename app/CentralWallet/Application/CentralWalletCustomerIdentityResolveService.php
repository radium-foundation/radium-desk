<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Support\TrustedVerificationMethod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Trusted spoke identity resolution: verified email or Google subject attested by the
 * authenticated integration caller. Creates or returns a Desk account link with a
 * trusted verification method (not canonical/migration identity).
 */
final class CentralWalletCustomerIdentityResolveService
{
    public const ACTOR_ID_PREFIX = 'customer_identity_resolve';

    public function __construct(
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
        private readonly CentralWalletService $wallets,
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    /**
     * @param  array<string, mixed>  $identity
     * @return array{status: int, body: array<string, mixed>}
     */
    public function resolve(
        string $siteCode,
        string $localUserId,
        array $identity,
        ?string $correlationId = null,
    ): array {
        if (! $this->isEnabled()) {
            return [
                'status' => 503,
                'body' => ['error' => 'customer_identity_resolve_disabled'],
            ];
        }

        $siteCode = trim($siteCode);
        $localUserId = trim($localUserId);
        if ($siteCode === '' || $localUserId === '') {
            return [
                'status' => 422,
                'body' => ['error' => 'validation_error', 'message' => 'site_code and local_user_id are required.'],
            ];
        }

        try {
            $credential = $this->subjectHasher->fromTrustedIdentityPayload($identity);
        } catch (InvalidArgumentException $exception) {
            if (in_array($exception->getMessage(), [
                'unsupported_identity_type',
                'verified_email_invalid',
                'google_subject_required',
                'verified_mobile_invalid',
            ], true)) {
                return [
                    'status' => 422,
                    'body' => ['error' => 'unsupported_identity_type'],
                ];
            }

            throw $exception;
        }

        $verificationMethod = $this->trustedVerificationMethodForCredential($credential['credential_type']);
        if ($verificationMethod === null) {
            return [
                'status' => 422,
                'body' => ['error' => 'unsupported_identity_type'],
            ];
        }

        try {
            return DB::transaction(function () use (
                $siteCode,
                $localUserId,
                $credential,
                $verificationMethod,
                $correlationId,
            ): array {
                $existingLink = CentralWalletAccountLink::query()
                    ->where('site_code', $siteCode)
                    ->where('local_user_id', $localUserId)
                    ->where('status', AccountLinkStatus::Active)
                    ->lockForUpdate()
                    ->first();

                $cwids = $this->cwidsForCredential(
                    $credential['credential_type'],
                    $credential['provider'],
                    $credential['subject_hash'],
                );

                if (count($cwids) > 1) {
                    return $this->ambiguous($siteCode, $localUserId, $correlationId);
                }

                $provisioned = false;
                if (count($cwids) === 1) {
                    $cwid = $cwids[0];
                    $customer = CentralCustomer::query()
                        ->where('central_wallet_id', $cwid)
                        ->lockForUpdate()
                        ->first();
                    if ($customer === null) {
                        return [
                            'status' => 404,
                            'body' => ['error' => 'not_found'],
                        ];
                    }
                } else {
                    $customer = $this->provisionCustomerWithCredential(
                        $credential['credential_type'],
                        $credential['provider'],
                        $credential['subject_hash'],
                        $siteCode,
                        $localUserId,
                        $correlationId,
                    );
                    $cwid = (string) $customer->central_wallet_id;
                    $provisioned = true;
                }

                if ($existingLink !== null) {
                    if ((string) $existingLink->central_wallet_id !== $cwid) {
                        return [
                            'status' => 409,
                            'body' => ['error' => 'account_link_cwid_conflict'],
                        ];
                    }

                    $link = $this->upgradeLinkToTrustedIfNeeded(
                        $existingLink,
                        $customer,
                        $verificationMethod,
                        $siteCode,
                        $localUserId,
                        $correlationId,
                    );

                    return $this->successBody(
                        $link,
                        $customer,
                        $provisioned ? 'created_customer' : 'resolved_existing_link',
                        200,
                    );
                }

                $link = $this->createTrustedActiveLink(
                    $customer,
                    $siteCode,
                    $localUserId,
                    $verificationMethod,
                    $correlationId,
                );

                return $this->successBody(
                    $link,
                    $customer,
                    $provisioned ? 'created_customer' : 'linked_existing',
                    201,
                );
            });
        } catch (InvalidArgumentException $exception) {
            if (in_array($exception->getMessage(), [
                'ambiguous_email_identity',
                'credential_conflict',
                'cwid_site_link_conflict',
            ], true)) {
                return $this->ambiguous($siteCode, $localUserId, $correlationId);
            }

            return [
                'status' => 422,
                'body' => ['error' => 'identity_resolution_failed', 'message' => $exception->getMessage()],
            ];
        } catch (Throwable) {
            return [
                'status' => 503,
                'body' => ['error' => 'unavailable'],
            ];
        }
    }

    public function isEnabled(): bool
    {
        return filter_var(
            config('central_wallet.customer_identity_resolve.enabled', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    private function trustedVerificationMethodForCredential(CustomerIdentityCredentialType $type): ?string
    {
        return match ($type) {
            CustomerIdentityCredentialType::VerifiedEmail => 'verified_email',
            CustomerIdentityCredentialType::Google => 'trusted_google',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function cwidsForCredential(
        CustomerIdentityCredentialType $type,
        string $provider,
        string $subjectHash,
    ): array {
        $customerIds = CentralCustomerIdentityCredential::query()
            ->where('credential_type', $type)
            ->where('provider', $provider)
            ->where('subject_hash', $subjectHash)
            ->whereNotNull('verified_at')
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

    private function provisionCustomerWithCredential(
        CustomerIdentityCredentialType $type,
        string $provider,
        string $subjectHash,
        string $siteCode,
        string $localUserId,
        ?string $correlationId,
    ): CentralCustomer {
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
                'credential_type' => $type,
                'provider' => $provider,
                'subject_hash' => $subjectHash,
                'verified_at' => now(),
                'metadata' => [
                    'source' => self::ACTOR_ID_PREFIX,
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
            eventType: 'customer_identity.resolve_established',
            centralWalletId: $wallet->id,
            actorType: AuditActorType::Service,
            actorId: self::ACTOR_ID_PREFIX.':'.$siteCode,
            correlationId: $correlationId,
            payload: [
                'desk_customer_id' => $customerId,
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'credential_type' => $type->value,
            ],
        );

        return $customer;
    }

    private function createTrustedActiveLink(
        CentralCustomer $customer,
        string $siteCode,
        string $localUserId,
        string $verificationMethod,
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

        $link = CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $customer->central_wallet_id,
            'desk_customer_id' => $customer->id,
            'site_code' => $siteCode,
            'local_user_id' => $localUserId,
            'status' => AccountLinkStatus::Active,
            'verification_method' => $verificationMethod,
            'created_by' => self::ACTOR_ID_PREFIX.':'.$siteCode,
            'linked_at' => now(),
            'metadata' => ['source' => self::ACTOR_ID_PREFIX],
        ]);

        $this->auditEvents->record(
            eventType: 'link.trusted_identity_resolved',
            centralWalletId: (string) $customer->central_wallet_id,
            actorType: AuditActorType::Service,
            actorId: self::ACTOR_ID_PREFIX.':'.$siteCode,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'link_id' => $link->id,
                'verification_method' => $verificationMethod,
            ],
        );

        return $link;
    }

    private function upgradeLinkToTrustedIfNeeded(
        CentralWalletAccountLink $link,
        CentralCustomer $customer,
        string $verificationMethod,
        string $siteCode,
        string $localUserId,
        ?string $correlationId,
    ): CentralWalletAccountLink {
        $needsUpgrade = ! TrustedVerificationMethod::isTrusted($link->verification_method)
            || trim((string) $link->desk_customer_id) === '';

        if ($needsUpgrade) {
            $link->fill([
                'desk_customer_id' => $customer->id,
                'verification_method' => $verificationMethod,
                'linked_at' => $link->linked_at ?? now(),
            ]);
            $link->save();

            $this->auditEvents->record(
                eventType: 'link.trusted_identity_upgraded',
                centralWalletId: (string) $link->central_wallet_id,
                actorType: AuditActorType::Service,
                actorId: self::ACTOR_ID_PREFIX.':'.$siteCode,
                correlationId: $correlationId,
                payload: [
                    'site_code' => $siteCode,
                    'local_user_id' => $localUserId,
                    'link_id' => $link->id,
                    'verification_method' => $verificationMethod,
                ],
            );
        }

        return $link->fresh();
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function successBody(
        CentralWalletAccountLink $link,
        CentralCustomer $customer,
        string $provisionAction,
        int $status,
    ): array {
        return [
            'status' => $status,
            'body' => [
                'desk_customer_id' => (string) $customer->id,
                'central_wallet_id' => (string) $link->central_wallet_id,
                'link_id' => $link->id,
                'link_status' => $link->status->value,
                'verification_method' => (string) $link->verification_method,
                'provision_action' => $provisionAction,
            ],
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function ambiguous(string $siteCode, string $localUserId, ?string $correlationId): array
    {
        $this->auditEvents->record(
            eventType: 'customer_identity.resolve_ambiguous',
            centralWalletId: null,
            actorType: AuditActorType::Service,
            actorId: self::ACTOR_ID_PREFIX.':'.$siteCode,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
            ],
        );

        return [
            'status' => 409,
            'body' => ['error' => 'identity_ambiguous'],
        ];
    }
}
