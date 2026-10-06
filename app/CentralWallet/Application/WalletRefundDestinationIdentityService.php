<?php

namespace App\CentralWallet\Application;

/**
 * Resolves Desk CWID for wallet refund credits via canonical customer identity ensure.
 * Delegates to CentralWalletCustomerIdentityEnsureService — spending authorization remains separate.
 */
final class WalletRefundDestinationIdentityService
{
    public function __construct(
        private readonly CentralWalletCustomerIdentityEnsureService $customerIdentityEnsure,
    ) {}

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function resolve(
        string $siteCode,
        string $localUserId,
        ?string $email,
        ?string $mobile,
        ?string $correlationId = null,
    ): array {
        return $this->customerIdentityEnsure->ensure(
            $siteCode,
            $localUserId,
            $email,
            $mobile,
            $correlationId,
        );
    }

    public function isEnabled(): bool
    {
        return $this->customerIdentityEnsure->isEnabled();
    }
}
