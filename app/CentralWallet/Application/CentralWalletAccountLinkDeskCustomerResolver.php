<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use InvalidArgumentException;

/**
 * Resolves the Desk customer bound to an account link without trusting a NULL desk_customer_id.
 * Read-only: does not mutate links. Used for visibility, ensure parity, and repair classification.
 */
final class CentralWalletAccountLinkDeskCustomerResolver
{
    public function __construct(
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
    ) {}

    /**
     * @return array{status: string, desk_customer_id: ?string, reason: ?string}
     */
    public function classifyActiveLink(CentralWalletAccountLink $link): array
    {
        $deskCustomerId = trim((string) ($link->desk_customer_id ?? ''));
        if ($deskCustomerId !== '') {
            $customer = CentralCustomer::query()
                ->whereKey($deskCustomerId)
                ->where('central_wallet_id', $link->central_wallet_id)
                ->first();

            return $customer !== null
                ? ['status' => 'complete', 'desk_customer_id' => $deskCustomerId, 'reason' => null]
                : ['status' => 'integrity_defect', 'desk_customer_id' => null, 'reason' => 'desk_customer_id_wallet_mismatch'];
        }

        return $this->classifyIncompleteLink($link);
    }

    public function resolveDeskCustomerForActiveLink(
        CentralWalletAccountLink $link,
        string $siteCode,
        string $localUserId,
        ?string $email,
        ?string $mobile,
    ): ?CentralCustomer {
        $classification = $this->classifyActiveLink($link);
        if ($classification['status'] === 'complete' && $classification['desk_customer_id'] !== null) {
            return CentralCustomer::query()->find($classification['desk_customer_id']);
        }

        if ($classification['status'] !== 'safely_mappable') {
            return null;
        }

        $customer = $this->soleCustomerForWallet((string) $link->central_wallet_id);
        if ($customer === null) {
            return null;
        }

        if (! $this->spokeIdentitySupportedByCustomer($customer, $siteCode, $localUserId, $email, $mobile)) {
            return null;
        }

        return $customer;
    }

    public function contactMatchesResolvedCustomer(
        CentralCustomer $customer,
        string $email,
        string $mobileDigits,
    ): bool {
        return $this->contactMatchesLinkedCustomer((string) $customer->id, $email, $mobileDigits);
    }

    /**
     * @return array{status: string, desk_customer_id: ?string, reason: ?string}
     */
    private function classifyIncompleteLink(CentralWalletAccountLink $link): array
    {
        $customer = $this->soleCustomerForWallet((string) $link->central_wallet_id);
        if ($customer === null) {
            $count = CentralCustomer::query()
                ->where('central_wallet_id', $link->central_wallet_id)
                ->count();

            if ($count === 0) {
                return ['status' => 'unmatched', 'desk_customer_id' => null, 'reason' => 'no_desk_customer_for_wallet'];
            }

            return ['status' => 'ambiguous', 'desk_customer_id' => null, 'reason' => 'multiple_desk_customers_for_wallet'];
        }

        if ($this->credentialMetadataMatchesLink($customer, (string) $link->site_code, (string) $link->local_user_id)) {
            return ['status' => 'safely_mappable', 'desk_customer_id' => (string) $customer->id, 'reason' => 'credential_metadata_match'];
        }

        return ['status' => 'unmatched', 'desk_customer_id' => null, 'reason' => 'no_trusted_credential_metadata_match'];
    }

    private function soleCustomerForWallet(string $centralWalletId): ?CentralCustomer
    {
        $customers = CentralCustomer::query()
            ->where('central_wallet_id', $centralWalletId)
            ->get();

        if ($customers->count() !== 1) {
            return null;
        }

        return $customers->first();
    }

    private function credentialMetadataMatchesLink(
        CentralCustomer $customer,
        string $siteCode,
        string $localUserId,
    ): bool {
        return CentralCustomerIdentityCredential::query()
            ->where('desk_customer_id', $customer->id)
            ->whereNotNull('verified_at')
            ->where('metadata->site_code', $siteCode)
            ->where('metadata->local_user_id', $localUserId)
            ->exists();
    }

    private function spokeIdentitySupportedByCustomer(
        CentralCustomer $customer,
        string $siteCode,
        string $localUserId,
        ?string $email,
        ?string $mobile,
    ): bool {
        if ($this->credentialMetadataMatchesLink($customer, $siteCode, $localUserId)) {
            return true;
        }

        $email = strtolower(trim((string) $email));
        $mobileDigits = preg_replace('/\D+/', '', (string) $mobile) ?? '';

        return $this->contactMatchesLinkedCustomer((string) $customer->id, $email, $mobileDigits);
    }

    private function contactMatchesLinkedCustomer(string $deskCustomerId, string $email, string $mobileDigits): bool
    {
        if ($deskCustomerId === '') {
            return false;
        }

        if ($email !== '') {
            try {
                $emailHash = $this->subjectHasher->hashVerifiedEmail($email);
            } catch (InvalidArgumentException) {
                return false;
            }

            $emailMatch = CentralCustomerIdentityCredential::query()
                ->where('desk_customer_id', $deskCustomerId)
                ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
                ->where('provider', 'desk_email')
                ->where('subject_hash', $emailHash)
                ->whereNotNull('verified_at')
                ->exists();

            if ($emailMatch) {
                return true;
            }
        }

        if (strlen($mobileDigits) >= 10) {
            $e164 = '+91'.$mobileDigits;

            try {
                $mobileHash = $this->subjectHasher->hashVerifiedMobileE164($e164);
            } catch (InvalidArgumentException) {
                return false;
            }

            return CentralCustomerIdentityCredential::query()
                ->where('desk_customer_id', $deskCustomerId)
                ->where('credential_type', CustomerIdentityCredentialType::VerifiedMobile)
                ->where('provider', 'desk_mobile')
                ->where('subject_hash', $mobileHash)
                ->whereNotNull('verified_at')
                ->exists();
        }

        return false;
    }
}
