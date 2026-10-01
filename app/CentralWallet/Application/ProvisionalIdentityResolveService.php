<?php

namespace App\CentralWallet\Application;

final class ProvisionalIdentityResolveService
{
    public function __construct(
        private readonly HistoricalWalletVisibilityService $visibility,
    ) {}

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function resolve(
        string $siteCode,
        string $localUserId,
        string $email,
        bool $emailVerified,
        ?string $correlationId = null,
        ?string $mobile = null,
    ): array {
        if (! (bool) config('central_wallet.provisional_identity.enabled', false)) {
            return [
                'status' => 503,
                'body' => ['error' => 'provisional_identity_disabled'],
            ];
        }

        return $this->visibility->resolve(
            siteCode: $siteCode,
            localUserId: $localUserId,
            email: $email,
            emailVerified: $emailVerified,
            correlationId: $correlationId,
            mobile: $mobile,
        );
    }
}
