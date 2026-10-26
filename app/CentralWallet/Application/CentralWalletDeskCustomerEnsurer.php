<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Ensures exactly one Desk central_customers row exists per central wallet when activating account links.
 */
final class CentralWalletDeskCustomerEnsurer
{
    public function __construct(
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
    ) {}

    public function ensureForWallet(string $centralWalletId): CentralCustomer
    {
        $existing = CentralCustomer::query()
            ->where('central_wallet_id', $centralWalletId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return CentralCustomer::query()->create([
            'id' => (string) Str::uuid(),
            'central_wallet_id' => $centralWalletId,
            'status' => 'active',
        ]);
    }

    public function requireDeskCustomerIdForWallet(string $centralWalletId): string
    {
        $customer = $this->ensureForWallet($centralWalletId);
        $deskCustomerId = trim((string) $customer->id);

        if ($deskCustomerId === '') {
            throw new InvalidArgumentException('central_wallet_customer_missing');
        }

        return $deskCustomerId;
    }

    public function soleDeskCustomerIdForWallet(string $centralWalletId): string
    {
        try {
            $customer = CentralCustomer::query()
                ->where('central_wallet_id', $centralWalletId)
                ->sole();
        } catch (ModelNotFoundException) {
            throw new InvalidArgumentException('central_wallet_customer_missing');
        }

        $deskCustomerId = trim((string) $customer->id);
        if ($deskCustomerId === '') {
            throw new InvalidArgumentException('central_wallet_customer_missing');
        }

        return $deskCustomerId;
    }

    public function ensureVerifiedMobileCredential(
        CentralCustomer $customer,
        string $verifiedPhoneE164Hash,
        string $siteCode,
        string $localUserId,
        string $source,
    ): void {
        if (trim($verifiedPhoneE164Hash) === '') {
            return;
        }

        $exists = CentralCustomerIdentityCredential::query()
            ->where('desk_customer_id', $customer->id)
            ->where('credential_type', CustomerIdentityCredentialType::VerifiedMobile)
            ->where('provider', 'desk_mobile')
            ->where('subject_hash', $verifiedPhoneE164Hash)
            ->whereNotNull('verified_at')
            ->exists();

        if ($exists) {
            return;
        }

        $ownedElsewhere = CentralCustomerIdentityCredential::query()
            ->where('desk_customer_id', '!=', $customer->id)
            ->where('credential_type', CustomerIdentityCredentialType::VerifiedMobile)
            ->where('provider', 'desk_mobile')
            ->where('subject_hash', $verifiedPhoneE164Hash)
            ->whereNotNull('verified_at')
            ->exists();

        if ($ownedElsewhere) {
            return;
        }

        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $customer->id,
            'credential_type' => CustomerIdentityCredentialType::VerifiedMobile,
            'provider' => 'desk_mobile',
            'subject_hash' => $verifiedPhoneE164Hash,
            'verified_at' => now(),
            'metadata' => [
                'source' => $source,
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
            ],
        ]);
    }
}
