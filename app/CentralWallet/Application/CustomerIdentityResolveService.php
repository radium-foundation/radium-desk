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
use RuntimeException;

final class CustomerIdentityResolveService
{
    public function __construct(
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
        private readonly CentralWalletService $wallets,
        private readonly AccountLinkService $accountLinks,
        private readonly AuditEventRecorder $auditEvents,
        private readonly E2VerificationDestinationService $e2VerificationDestination,
    ) {}

    /**
     * @param  array<string, mixed>  $identity
     * @return array{status: int, body: array<string, mixed>, resource_type?: string, resource_id?: string}
     */
    public function resolve(
        string $siteCode,
        string $localUserId,
        array $identity,
        ?string $correlationId = null,
    ): array {
        if (! (bool) config('central_wallet.customer_identity.enabled', false)) {
            return [
                'status' => 503,
                'body' => ['error' => 'customer_identity_disabled'],
            ];
        }

        try {
            $credential = $this->subjectHasher->fromTrustedIdentityPayload($identity);
        } catch (InvalidArgumentException $exception) {
            return [
                'status' => 422,
                'body' => ['error' => $exception->getMessage()],
            ];
        }

        if (! $this->isCredentialTypeEnabled($credential['credential_type'])) {
            return [
                'status' => 503,
                'body' => ['error' => 'customer_identity_credential_disabled'],
            ];
        }

        try {
            return DB::transaction(function () use (
                $siteCode,
                $localUserId,
                $credential,
                $identity,
                $correlationId,
            ): array {
                $existingLocalLink = CentralWalletAccountLink::query()
                    ->where('site_code', $siteCode)
                    ->where('local_user_id', $localUserId)
                    ->where('status', AccountLinkStatus::Active)
                    ->lockForUpdate()
                    ->first();

                $matchedCredentials = CentralCustomerIdentityCredential::query()
                    ->where('credential_type', $credential['credential_type'])
                    ->where('provider', $credential['provider'])
                    ->where('subject_hash', $credential['subject_hash'])
                    ->lockForUpdate()
                    ->get();

                if ($matchedCredentials->pluck('desk_customer_id')->unique()->count() > 1) {
                    $this->auditEvents->record(
                        eventType: 'customer_identity.ambiguous',
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

                    return [
                        'status' => 409,
                        'body' => ['error' => 'identity_ambiguous'],
                    ];
                }

                $matchedCredential = $matchedCredentials->first();
                $customer = $matchedCredential !== null
                    ? CentralCustomer::query()->lockForUpdate()->find($matchedCredential->desk_customer_id)
                    : null;

                if ($customer === null && $existingLocalLink !== null && $existingLocalLink->desk_customer_id !== null) {
                    $customer = CentralCustomer::query()->lockForUpdate()->find($existingLocalLink->desk_customer_id);
                    if ($customer !== null) {
                        $this->attachCredential($customer, $credential, $identity, $siteCode, $localUserId, $correlationId);
                        $provisionAction = 'attached_credential';
                    }
                }

                if ($customer === null) {
                    $customer = $this->provisionCustomer($credential, $identity, $siteCode, $localUserId, $correlationId);
                    $provisionAction = 'created_customer';
                } else {
                    $provisionAction ??= 'resolved_existing_customer';
                }

                if ($existingLocalLink !== null) {
                    if ($existingLocalLink->central_wallet_id !== $customer->central_wallet_id) {
                        $this->auditEvents->record(
                            eventType: 'customer_identity.link_conflict',
                            centralWalletId: $existingLocalLink->central_wallet_id,
                            actorType: AuditActorType::Service,
                            actorId: 'customer_identity:'.$siteCode,
                            correlationId: $correlationId,
                            payload: [
                                'site_code' => $siteCode,
                                'local_user_id' => $localUserId,
                                'existing_cwid' => $existingLocalLink->central_wallet_id,
                                'resolved_cwid' => $customer->central_wallet_id,
                            ],
                        );

                        return [
                            'status' => 409,
                            'body' => ['error' => 'identity_link_conflict'],
                        ];
                    }

                    if ($existingLocalLink->desk_customer_id === null) {
                        $existingLocalLink->desk_customer_id = $customer->id;
                        $existingLocalLink->save();
                    } elseif ($existingLocalLink->desk_customer_id !== $customer->id) {
                        return [
                            'status' => 409,
                            'body' => ['error' => 'identity_link_conflict'],
                        ];
                    }

                    $response = $this->successResponse(
                        customer: $customer,
                        link: $existingLocalLink,
                        provisionAction: 'resolved_local_link',
                        httpStatus: 200,
                    );
                    $this->prepareE2DestinationIfApplicable(
                        $siteCode,
                        $localUserId,
                        $customer,
                        $identity,
                        $correlationId,
                    );

                    return $response;
                }

                $verificationMethod = $this->verificationMethodForCredential($credential['credential_type']);
                $link = $this->accountLinks->createConfirmedLink(
                    centralWalletId: $customer->central_wallet_id,
                    siteCode: $siteCode,
                    localUserId: $localUserId,
                    createdBy: 'service:'.$siteCode,
                    verificationMethod: $verificationMethod,
                    actorId: 'customer:'.$localUserId,
                    correlationId: $correlationId,
                    deskCustomerId: $customer->id,
                );

                $this->auditEvents->record(
                    eventType: 'customer_identity.linked',
                    centralWalletId: $customer->central_wallet_id,
                    actorType: AuditActorType::Customer,
                    actorId: 'customer:'.$localUserId,
                    correlationId: $correlationId,
                    payload: [
                        'desk_customer_id' => $customer->id,
                        'site_code' => $siteCode,
                        'local_user_id' => $localUserId,
                        'link_id' => $link->id,
                        'verification_method' => $verificationMethod,
                        'provision_action' => $provisionAction,
                    ],
                );

                $response = $this->successResponse(
                    customer: $customer,
                    link: $link,
                    provisionAction: $provisionAction,
                    httpStatus: 201,
                );
                $this->prepareE2DestinationIfApplicable(
                    $siteCode,
                    $localUserId,
                    $customer,
                    $identity,
                    $correlationId,
                );

                return $response;
            });
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'already exists')) {
                return [
                    'status' => 409,
                    'body' => ['error' => 'link_conflict', 'message' => $exception->getMessage()],
                ];
            }

            throw $exception;
        } catch (QueryException $exception) {
            if ($this->isDuplicateCredentialException($exception)) {
                return [
                    'status' => 409,
                    'body' => ['error' => 'identity_ambiguous'],
                ];
            }

            throw $exception;
        }
    }

    /**
     * @param  array{credential_type: CustomerIdentityCredentialType, provider: string, subject_hash: string}  $credential
     * @param  array<string, mixed>  $identity
     */
    private function attachCredential(
        CentralCustomer $customer,
        array $credential,
        array $identity,
        string $siteCode,
        string $localUserId,
        ?string $correlationId,
    ): void {
        $exists = CentralCustomerIdentityCredential::query()
            ->where('desk_customer_id', $customer->id)
            ->where('credential_type', $credential['credential_type'])
            ->where('provider', $credential['provider'])
            ->where('subject_hash', $credential['subject_hash'])
            ->exists();

        if ($exists) {
            return;
        }

        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $customer->id,
            'credential_type' => $credential['credential_type'],
            'provider' => $credential['provider'],
            'subject_hash' => $credential['subject_hash'],
            'verified_at' => now(),
            'metadata' => $this->credentialMetadata($identity),
        ]);

        $this->auditEvents->record(
            eventType: 'customer_identity.credential_attached',
            centralWalletId: $customer->central_wallet_id,
            actorType: AuditActorType::Customer,
            actorId: 'customer:'.$localUserId,
            correlationId: $correlationId,
            payload: [
                'desk_customer_id' => $customer->id,
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'credential_type' => $credential['credential_type']->value,
            ],
        );
    }

    /**
     * @param  array{credential_type: CustomerIdentityCredentialType, provider: string, subject_hash: string}  $credential
     * @param  array<string, mixed>  $identity
     */
    private function provisionCustomer(
        array $credential,
        array $identity,
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

        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $customer->id,
            'credential_type' => $credential['credential_type'],
            'provider' => $credential['provider'],
            'subject_hash' => $credential['subject_hash'],
            'verified_at' => now(),
            'metadata' => $this->credentialMetadata($identity),
        ]);

        $this->auditEvents->record(
            eventType: 'customer_identity.created',
            centralWalletId: $wallet->id,
            actorType: AuditActorType::Service,
            actorId: 'customer_identity:'.$siteCode,
            correlationId: $correlationId,
            payload: [
                'desk_customer_id' => $customer->id,
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'credential_type' => $credential['credential_type']->value,
            ],
        );

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    private function credentialMetadata(array $identity): array
    {
        return match ((string) ($identity['type'] ?? '')) {
            CustomerIdentityCredentialType::Google->value => [
                'source' => 'google',
            ],
            CustomerIdentityCredentialType::VerifiedEmail->value => [
                'source' => 'verified_email',
            ],
            CustomerIdentityCredentialType::VerifiedMobile->value => [
                'source' => 'verified_mobile',
            ],
            default => [],
        };
    }

    private function verificationMethodForCredential(CustomerIdentityCredentialType $type): string
    {
        return match ($type) {
            CustomerIdentityCredentialType::Google => 'trusted_google',
            CustomerIdentityCredentialType::VerifiedEmail => 'verified_email',
            CustomerIdentityCredentialType::VerifiedMobile => 'verified_mobile',
        };
    }

    private function isCredentialTypeEnabled(CustomerIdentityCredentialType $type): bool
    {
        $config = config('central_wallet.customer_identity', []);

        return match ($type) {
            CustomerIdentityCredentialType::Google => (bool) ($config['google_enabled'] ?? false),
            CustomerIdentityCredentialType::VerifiedEmail => (bool) ($config['verified_email_enabled'] ?? false),
            CustomerIdentityCredentialType::VerifiedMobile => (bool) ($config['verified_mobile_enabled'] ?? false),
        };
    }

    /**
     * @return array{status: int, body: array<string, mixed>, resource_type: string, resource_id: string}
     */
    private function successResponse(
        CentralCustomer $customer,
        CentralWalletAccountLink $link,
        string $provisionAction,
        int $httpStatus,
    ): array {
        return [
            'status' => $httpStatus,
            'body' => [
                'desk_customer_id' => $customer->id,
                'central_wallet_id' => $customer->central_wallet_id,
                'link_id' => $link->id,
                'link_status' => $link->status->value,
                'verification_method' => $link->verification_method,
                'provision_action' => $provisionAction,
            ],
            'resource_type' => 'desk_customer',
            'resource_id' => $customer->id,
        ];
    }

    private function isDuplicateCredentialException(QueryException $exception): bool
    {
        return str_contains($exception->getMessage(), 'central_customer_credentials_subject_uq');
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function prepareE2DestinationIfApplicable(
        string $siteCode,
        string $localUserId,
        CentralCustomer $customer,
        array $identity,
        ?string $correlationId,
    ): void {
        $this->e2VerificationDestination->prepareAfterTrustedVerification(
            siteCode: $siteCode,
            localUserId: $localUserId,
            deskCustomerId: $customer->id,
            cwid: $customer->central_wallet_id,
            identity: $identity,
            correlationId: $correlationId,
        );
    }
}
