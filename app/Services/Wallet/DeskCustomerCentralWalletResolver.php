<?php

namespace App\Services\Wallet;

use App\CentralWallet\Application\CashfreeCentralCustomerBinder;
use App\CentralWallet\Application\CustomerIdentitySubjectHasher;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\Models\Incident;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Resolves one Desk customer and that customer's Central Wallet from a case.
 *
 * Priority: authoritative order.customer_id (Cashfree bind), then legacy verified
 * desk_email credential. Account links are not consulted for Customer 360 display.
 */
final class DeskCustomerCentralWalletResolver
{
    public function __construct(
        private readonly CustomerIdentitySubjectHasher $hasher,
        private readonly CashfreeCentralCustomerBinder $cashfreeBinder,
    ) {}

    /**
     * @return array{
     *     state: 'resolved',
     *     customer_id: string,
     *     central_wallet_id: string,
     *     wallet_status: string,
     * }|array{state: 'unresolved'}
     */
    public function forIncident(Incident $incident): array
    {
        $incident->loadMissing('order');

        $recordedCustomerId = trim((string) ($incident->order?->customer_id ?? ''));
        if ($recordedCustomerId !== '' && Str::isUuid($recordedCustomerId)) {
            if ($this->orderCustomerConflictsWithEmailIdentity($incident, $recordedCustomerId)) {
                return ['state' => 'unresolved'];
            }

            $fromOrder = $this->cashfreeBinder->resolveCustomerWallet($recordedCustomerId);
            if ($fromOrder !== null) {
                return $fromOrder;
            }

            return ['state' => 'unresolved'];
        }

        return $this->resolveFromVerifiedEmailCredential($incident);
    }

    /**
     * @return array{
     *     state: 'resolved',
     *     customer_id: string,
     *     central_wallet_id: string,
     *     wallet_status: string,
     * }|array{state: 'unresolved'}
     */
    private function orderCustomerConflictsWithEmailIdentity(Incident $incident, string $boundCustomerId): bool
    {
        $email = strtolower(trim((string) ($incident->order?->customer_email ?? '')));
        if ($email === '') {
            return false;
        }

        try {
            $hash = $this->hasher->hashVerifiedEmail($email);
        } catch (InvalidArgumentException) {
            return false;
        }

        $customerIds = CentralCustomerIdentityCredential::query()
            ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
            ->whereIn('provider', [
                CashfreeCentralCustomerBinder::PROVIDER_DESK_EMAIL,
                CashfreeCentralCustomerBinder::PROVIDER_CASHFREE_ORDER_EMAIL,
            ])
            ->where('subject_hash', $hash)
            ->whereNotNull('verified_at')
            ->pluck('desk_customer_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();

        if ($customerIds->count() === 0) {
            return false;
        }

        if ($customerIds->count() > 1) {
            return true;
        }

        return (string) $customerIds->first() !== $boundCustomerId;
    }

    private function resolveFromVerifiedEmailCredential(Incident $incident): array
    {
        $email = strtolower(trim((string) ($incident->order?->customer_email ?? '')));
        if ($email === '') {
            return ['state' => 'unresolved'];
        }

        try {
            $hash = $this->hasher->hashVerifiedEmail($email);
        } catch (InvalidArgumentException) {
            return ['state' => 'unresolved'];
        }

        $customerIds = CentralCustomerIdentityCredential::query()
            ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
            ->where('provider', 'desk_email')
            ->where('subject_hash', $hash)
            ->whereNotNull('verified_at')
            ->pluck('desk_customer_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();

        if ($customerIds->count() !== 1) {
            return ['state' => 'unresolved'];
        }

        $customer = CentralCustomer::query()->find($customerIds->first());
        if ($customer === null || trim((string) $customer->central_wallet_id) === '') {
            return ['state' => 'unresolved'];
        }

        $centralWalletId = (string) $customer->central_wallet_id;
        $ownerIds = CentralCustomer::query()
            ->where('central_wallet_id', $centralWalletId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id);

        if ($ownerIds->count() !== 1 || $ownerIds->first() !== (string) $customer->id) {
            return ['state' => 'unresolved'];
        }

        $wallet = CentralWallet::query()->find($centralWalletId);
        if ($wallet === null) {
            return ['state' => 'unresolved'];
        }

        return [
            'state' => 'resolved',
            'customer_id' => (string) $customer->id,
            'central_wallet_id' => $centralWalletId,
            'wallet_status' => (string) $wallet->status,
        ];
    }
}
