<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use InvalidArgumentException;

/**
 * Server-side Desk identity → central_wallet_id resolution for read-only display paths.
 * Shared by wallet-visibility and display-email ledger-history authorization.
 */
final class WalletDisplayIdentityResolver
{
    public function __construct(
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
    ) {}

    /**
     * @return array{central_wallet_id: string, verification_method: ?string, match_basis: string}|null
     *
     * @throws InvalidArgumentException ambiguous_email_identity|ambiguous_mobile_identity|credential_conflict
     */
    public function resolve(
        string $siteCode,
        string $localUserId,
        ?string $email,
        ?string $mobile,
    ): ?array {
        $email = strtolower(trim((string) $email));
        $mobileDigits = preg_replace('/\D+/', '', (string) $mobile) ?? '';

        if ($email === '' && strlen($mobileDigits) < 10) {
            return null;
        }

        $link = CentralWalletAccountLink::query()
            ->where('site_code', $siteCode)
            ->where('local_user_id', $localUserId)
            ->where('status', AccountLinkStatus::Active)
            ->first();

        if ($link !== null) {
            if (! $this->contactMatchesLinkedCustomer((string) $link->desk_customer_id, $email, $mobileDigits)) {
                return null;
            }

            return [
                'central_wallet_id' => (string) $link->central_wallet_id,
                'verification_method' => $link->verification_method,
                'match_basis' => 'account_link',
            ];
        }

        $credentialCwids = $this->resolveCwidsFromCredentials($email, $mobileDigits);
        if (count($credentialCwids) > 1) {
            throw new InvalidArgumentException('credential_conflict');
        }

        if (count($credentialCwids) === 1) {
            $customer = CentralCustomer::query()
                ->where('central_wallet_id', $credentialCwids[0])
                ->first();

            $linkForCustomer = $customer !== null
                ? CentralWalletAccountLink::query()
                    ->where('site_code', $siteCode)
                    ->where('local_user_id', $localUserId)
                    ->where('desk_customer_id', $customer->id)
                    ->where('status', AccountLinkStatus::Active)
                    ->first()
                : null;

            return [
                'central_wallet_id' => $credentialCwids[0],
                'verification_method' => $linkForCustomer?->verification_method,
                'match_basis' => 'credential',
            ];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function resolveCwidsFromCredentials(string $email, string $mobileDigits): array
    {
        $customerIds = collect();

        if ($email !== '') {
            try {
                $emailHash = $this->subjectHasher->hashVerifiedEmail($email);
            } catch (InvalidArgumentException) {
                $emailHash = null;
            }

            if ($emailHash !== null) {
                $emailMatches = CentralCustomerIdentityCredential::query()
                    ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
                    ->where('provider', 'desk_email')
                    ->where('subject_hash', $emailHash)
                    ->whereNotNull('verified_at')
                    ->pluck('desk_customer_id');

                if ($emailMatches->count() > 1) {
                    throw new InvalidArgumentException('ambiguous_email_identity');
                }

                $customerIds = $customerIds->merge($emailMatches);
            }
        }

        if (strlen($mobileDigits) >= 10) {
            $e164 = '+91'.ltrim($mobileDigits, '0');
            if (strlen($mobileDigits) === 10) {
                $e164 = '+91'.$mobileDigits;
            }

            try {
                $mobileHash = $this->subjectHasher->hashVerifiedMobileE164($e164);
            } catch (InvalidArgumentException) {
                $mobileHash = null;
            }

            if ($mobileHash !== null) {
                $mobileMatches = CentralCustomerIdentityCredential::query()
                    ->where('credential_type', CustomerIdentityCredentialType::VerifiedMobile)
                    ->where('provider', 'desk_mobile')
                    ->where('subject_hash', $mobileHash)
                    ->whereNotNull('verified_at')
                    ->pluck('desk_customer_id');

                if ($mobileMatches->count() > 1) {
                    throw new InvalidArgumentException('ambiguous_mobile_identity');
                }

                if ($mobileMatches->isNotEmpty()) {
                    if ($customerIds->isNotEmpty() && $mobileMatches->first() !== $customerIds->first()) {
                        throw new InvalidArgumentException('credential_conflict');
                    }

                    $customerIds = $customerIds->merge($mobileMatches);
                }
            }
        }

        if ($customerIds->unique()->count() > 1) {
            throw new InvalidArgumentException('credential_conflict');
        }

        if ($customerIds->isEmpty()) {
            return [];
        }

        $cwid = CentralCustomer::query()
            ->where('id', $customerIds->first())
            ->value('central_wallet_id');

        return is_string($cwid) && trim($cwid) !== '' ? [(string) $cwid] : [];
    }

    private function contactMatchesLinkedCustomer(string $deskCustomerId, string $email, string $mobileDigits): bool
    {
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
