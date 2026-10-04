<?php

namespace App\Services\Wallet;

use App\CentralWallet\Application\CustomerIdentitySubjectHasher;
use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Models\Incident;
use App\Models\RefundRequest;
use App\Services\OrderLookup\SpokeOrderClientFactory;
use App\Support\BusinessOrderId;
use InvalidArgumentException;

/**
 * Read-only Central Wallet identity resolution for Customer 360.
 * Does not create wallets, links, or ledger entries.
 */
final class Customer360CentralWalletIdentityLookup
{
    public function __construct(
        private readonly CustomerIdentitySubjectHasher $subjectHasher,
        private readonly SpokeOrderClientFactory $spokeClients,
    ) {}

    /**
     * @return array{
     *     central_wallet_id: string,
     *     site_code: string,
     *     local_user_id: ?string,
     *     match_basis: string,
     * }|null
     */
    public function resolveForIncident(Incident $incident): ?array
    {
        $incident->loadMissing('order');
        $order = $incident->order;
        if ($order === null) {
            return null;
        }

        $orderId = trim((string) $order->order_id);
        $siteCode = BusinessOrderId::owner($orderId);
        if (! is_string($siteCode) || $siteCode === '') {
            return null;
        }

        $email = strtolower(trim((string) $order->customer_email));
        $localUserId = $this->resolveSpokeLocalUserId($siteCode, $orderId);

        if ($localUserId !== null) {
            $link = CentralWalletAccountLink::query()
                ->where('site_code', $siteCode)
                ->where('local_user_id', $localUserId)
                ->where('status', AccountLinkStatus::Active)
                ->first();

            if ($link !== null) {
                return [
                    'central_wallet_id' => (string) $link->central_wallet_id,
                    'site_code' => $siteCode,
                    'local_user_id' => $localUserId,
                    'match_basis' => 'account_link',
                ];
            }
        }

        $credentialCwid = $this->resolveSingleCwidFromCredentials($email);
        if ($credentialCwid !== null) {
            return [
                'central_wallet_id' => $credentialCwid,
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'match_basis' => 'credential',
            ];
        }

        $refundCwid = $this->resolveCwidFromIncidentRefundLedger($incident);
        if ($refundCwid !== null) {
            return [
                'central_wallet_id' => $refundCwid,
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'match_basis' => 'refund_ledger',
            ];
        }

        return null;
    }

    private function resolveSpokeLocalUserId(string $siteCode, string $orderId): ?string
    {
        $client = $this->spokeClients->make($siteCode);
        if (! $client->isConfigured() || ! $client->isEligible($orderId)) {
            return null;
        }

        $payload = $client->fetchPayload($orderId);
        if (! is_array($payload)) {
            return null;
        }

        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }

        $rdOrder = $data['rd_order'] ?? null;
        if (! is_array($rdOrder)) {
            return null;
        }

        $userId = $rdOrder['userid'] ?? $rdOrder['customer_user_id'] ?? null;
        if (! is_numeric($userId) || (int) $userId <= 0) {
            return null;
        }

        return (string) (int) $userId;
    }

    private function resolveSingleCwidFromCredentials(string $email): ?string
    {
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        try {
            $emailHash = $this->subjectHasher->hashVerifiedEmail($email);
        } catch (InvalidArgumentException) {
            return null;
        }

        $customerIds = CentralCustomerIdentityCredential::query()
            ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
            ->where('provider', 'desk_email')
            ->where('subject_hash', $emailHash)
            ->pluck('desk_customer_id')
            ->unique()
            ->values()
            ->all();

        if ($customerIds === []) {
            return null;
        }

        $cwids = CentralCustomer::query()
            ->whereIn('id', $customerIds)
            ->pluck('central_wallet_id')
            ->unique()
            ->values()
            ->all();

        if (count($cwids) !== 1) {
            return null;
        }

        return (string) $cwids[0];
    }

    private function resolveCwidFromIncidentRefundLedger(Incident $incident): ?string
    {
        $references = RefundRequest::query()
            ->where('incident_id', $incident->id)
            ->pluck('reference_no')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->values()
            ->all();

        if ($references === []) {
            return null;
        }

        $cwids = CentralWalletLedgerEntry::query()
            ->whereIn('business_reference', $references)
            ->pluck('central_wallet_id')
            ->unique()
            ->values()
            ->all();

        if (count($cwids) !== 1) {
            return null;
        }

        return (string) $cwids[0];
    }
}
