<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\CeremonyVerificationProof;
use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletCeremonyIdentity;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletCeremonyProofConsumption;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CeremonyCompleteService
{
    public function __construct(
        private readonly CeremonyVerificationProofValidator $proofValidator,
        private readonly CentralWalletService $wallets,
        private readonly AccountLinkService $accountLinks,
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    /**
     * @return array{status: int, body: array<string, mixed>, resource_type?: string, resource_id?: string}
     */
    public function complete(
        string $siteCode,
        string $localUserId,
        string $ceremonyVerificationRef,
        ?string $verificationMethod,
        ?string $correlationId,
    ): array {
        try {
            $validated = $this->proofValidator->validate(
                ceremonyVerificationRef: $ceremonyVerificationRef,
                expectedSiteCode: $siteCode,
                expectedLocalUserId: $localUserId,
                expectedVerificationMethod: $verificationMethod,
            );
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
            $httpStatus = $error === 'ceremony_proof_replayed' ? 422 : 422;
            $errorCode = match ($error) {
                'ceremony_proof_replayed' => 'ceremony_proof_replayed',
                'invalid_ceremony_proof_expired' => 'invalid_ceremony_proof',
                default => 'invalid_ceremony_proof',
            };

            $this->auditEvents->record(
                eventType: 'ceremony.failed',
                centralWalletId: null,
                actorType: AuditActorType::Service,
                actorId: 'ceremony:'.$siteCode,
                correlationId: $correlationId,
                payload: [
                    'site_code' => $siteCode,
                    'local_user_id' => $localUserId,
                    'error' => $errorCode,
                ],
            );

            return [
                'status' => $httpStatus,
                'body' => ['error' => $errorCode],
            ];
        }

        $proof = $validated['proof'];

        try {
            return DB::transaction(function () use ($proof, $siteCode, $localUserId, $correlationId): array {
                try {
                    $this->consumeProof($proof);
                } catch (InvalidArgumentException $exception) {
                    if ($exception->getMessage() === 'ceremony_proof_replayed') {
                        return [
                            'status' => 422,
                            'body' => ['error' => 'ceremony_proof_replayed'],
                        ];
                    }

                    throw $exception;
                }

                $existingLocalLink = CentralWalletAccountLink::query()
                    ->where('site_code', $siteCode)
                    ->where('local_user_id', $localUserId)
                    ->where('status', AccountLinkStatus::Active)
                    ->lockForUpdate()
                    ->first();

                if ($existingLocalLink !== null) {
                    $this->auditEvents->record(
                        eventType: 'ceremony.wallet_resolved',
                        centralWalletId: $existingLocalLink->central_wallet_id,
                        actorType: AuditActorType::Customer,
                        actorId: 'customer:'.$localUserId,
                        correlationId: $correlationId,
                        payload: [
                            'site_code' => $siteCode,
                            'local_user_id' => $localUserId,
                            'provision_action' => 'resolved_local_link',
                            'link_id' => $existingLocalLink->id,
                        ],
                    );

                    return $this->successResponse(
                        centralWalletId: $existingLocalLink->central_wallet_id,
                        linkId: $existingLocalLink->id,
                        linkedAt: $existingLocalLink->linked_at ?? now(),
                        provisionAction: 'resolved_local_link',
                        httpStatus: 200,
                    );
                }

                $identity = CentralWalletCeremonyIdentity::query()
                    ->where('site_code', $siteCode)
                    ->where('phone_e164_hash', $proof->verifiedPhoneE164Hash)
                    ->lockForUpdate()
                    ->first();

                $provisionAction = 'created';
                $centralWalletId = $identity?->central_wallet_id;

                if ($centralWalletId !== null) {
                    $provisionAction = 'resolved_existing';
                }

                if ($centralWalletId !== null) {
                    $walletLinkedElsewhere = CentralWalletAccountLink::query()
                        ->where('site_code', $siteCode)
                        ->where('central_wallet_id', $centralWalletId)
                        ->where('status', AccountLinkStatus::Active)
                        ->where('local_user_id', '!=', $localUserId)
                        ->lockForUpdate()
                        ->exists();

                    if ($walletLinkedElsewhere) {
                        return $this->conflictResponse(
                            error: 'cwid_linked_elsewhere',
                            siteCode: $siteCode,
                            localUserId: $localUserId,
                            centralWalletId: $centralWalletId,
                            correlationId: $correlationId,
                        );
                    }
                }

                if ($centralWalletId === null) {
                    $wallet = $this->wallets->create($correlationId);
                    $centralWalletId = $wallet->id;
                    $provisionAction = 'created';

                    $this->auditEvents->record(
                        eventType: 'ceremony.wallet_created',
                        centralWalletId: $centralWalletId,
                        actorType: AuditActorType::Service,
                        actorId: 'ceremony:'.$siteCode,
                        correlationId: $correlationId,
                        payload: [
                            'site_code' => $siteCode,
                            'ceremony_attempt_id' => $proof->ceremonyAttemptId,
                        ],
                    );

                    if ($identity === null) {
                        $identity = CentralWalletCeremonyIdentity::query()->create([
                            'id' => (string) Str::uuid(),
                            'site_code' => $siteCode,
                            'phone_e164_hash' => $proof->verifiedPhoneE164Hash,
                            'central_wallet_id' => $centralWalletId,
                            'first_verified_at' => now(),
                        ]);
                    } else {
                        $identity->central_wallet_id = $centralWalletId;
                        $identity->save();
                    }
                } elseif ($identity === null) {
                    CentralWalletCeremonyIdentity::query()->create([
                        'id' => (string) Str::uuid(),
                        'site_code' => $siteCode,
                        'phone_e164_hash' => $proof->verifiedPhoneE164Hash,
                        'central_wallet_id' => $centralWalletId,
                        'first_verified_at' => now(),
                    ]);
                } else {
                    $this->auditEvents->record(
                        eventType: 'ceremony.wallet_resolved',
                        centralWalletId: $centralWalletId,
                        actorType: AuditActorType::Service,
                        actorId: 'ceremony:'.$siteCode,
                        correlationId: $correlationId,
                        payload: [
                            'site_code' => $siteCode,
                            'ceremony_attempt_id' => $proof->ceremonyAttemptId,
                            'provision_action' => 'resolved_existing',
                        ],
                    );
                }

                $link = $this->accountLinks->createConfirmedLink(
                    centralWalletId: $centralWalletId,
                    siteCode: $siteCode,
                    localUserId: $localUserId,
                    createdBy: 'ceremony:'.$siteCode,
                    verificationMethod: $proof->verificationMethod,
                    actorId: 'customer:'.$localUserId,
                    correlationId: $correlationId,
                );

                $this->auditEvents->record(
                    eventType: 'ceremony.linked',
                    centralWalletId: $centralWalletId,
                    actorType: AuditActorType::Customer,
                    actorId: 'customer:'.$localUserId,
                    correlationId: $correlationId,
                    payload: [
                        'site_code' => $siteCode,
                        'local_user_id' => $localUserId,
                        'link_id' => $link->id,
                        'provision_action' => $provisionAction,
                    ],
                );

                return $this->successResponse(
                    centralWalletId: $centralWalletId,
                    linkId: $link->id,
                    linkedAt: $link->linked_at ?? now(),
                    provisionAction: $provisionAction,
                    httpStatus: 201,
                );
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return $this->conflictResponse(
                    error: 'cwid_linked_elsewhere',
                    siteCode: $siteCode,
                    localUserId: $localUserId,
                    centralWalletId: null,
                    correlationId: $correlationId,
                );
            }

            throw $exception;
        } catch (\RuntimeException $exception) {
            return $this->conflictResponse(
                error: 'ceremony_identity_linked_elsewhere',
                siteCode: $siteCode,
                localUserId: $localUserId,
                centralWalletId: null,
                correlationId: $correlationId,
                message: $exception->getMessage(),
            );
        }
    }

    private function consumeProof(CeremonyVerificationProof $proof): void
    {
        $ttl = max(60, (int) config('central_wallet.ceremony.proof_ttl_seconds', 300));

        try {
            CentralWalletCeremonyProofConsumption::query()->create([
                'jti' => $proof->jti,
                'site_code' => $proof->siteCode,
                'local_user_id' => $proof->localUserId,
                'ceremony_attempt_id' => $proof->ceremonyAttemptId,
                'consumed_at' => now(),
                'expires_at' => now()->addSeconds($ttl),
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                throw new InvalidArgumentException('ceremony_proof_replayed');
            }

            throw $exception;
        }
    }

    /**
     * @return array{status: int, body: array<string, mixed>, resource_type: string, resource_id: string}
     */
    private function successResponse(
        string $centralWalletId,
        int $linkId,
        \DateTimeInterface $linkedAt,
        string $provisionAction,
        int $httpStatus,
    ): array {
        return [
            'status' => $httpStatus,
            'body' => [
                'status' => 'connected',
                'central_wallet_id' => $centralWalletId,
                'customer_display_ref' => $this->customerDisplayRef($linkId),
                'provision_action' => $provisionAction,
                'linked_at' => $linkedAt->format(\DateTimeInterface::ATOM),
                'idempotent' => false,
            ],
            'resource_type' => 'account_link',
            'resource_id' => (string) $linkId,
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function conflictResponse(
        string $error,
        string $siteCode,
        string $localUserId,
        ?string $centralWalletId,
        ?string $correlationId,
        ?string $message = null,
    ): array {
        $this->auditEvents->record(
            eventType: 'ceremony.conflict',
            centralWalletId: $centralWalletId,
            actorType: AuditActorType::Service,
            actorId: 'ceremony:'.$siteCode,
            correlationId: $correlationId,
            payload: [
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'error' => $error,
            ],
        );

        return [
            'status' => 409,
            'body' => [
                'error' => $error,
                'message' => $message ?? 'Ceremony completion conflict.',
            ],
        ];
    }

    private function customerDisplayRef(int $linkId): string
    {
        return 'CW-••••-'.str_pad((string) ($linkId % 10000), 4, '0', STR_PAD_LEFT);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();

        return $code === '23000' || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
