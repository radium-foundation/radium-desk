<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Domain\Enums\E1IdentityState;
use App\CentralWallet\Domain\Enums\E1MigrationDestinationState;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;

final class E1CohortStateResolver
{
    private const TRUSTED_CREDENTIAL_TYPES = [
        CustomerIdentityCredentialType::Google,
        CustomerIdentityCredentialType::VerifiedEmail,
        CustomerIdentityCredentialType::VerifiedMobile,
    ];

    public function __construct(
        private readonly E1CohortManifestLoader $cohortManifestLoader,
    ) {}

    /**
     * @return array{
     *     cohort_id: string,
     *     population: array{count: int, amount: string},
     *     identity_totals: array<string, array{count: int, amount: string}>,
     *     destination_totals: array<string, array{count: int, amount: string}>,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function audit(?string $manifestPath = null): array
    {
        $manifest = $this->cohortManifestLoader->load($manifestPath);

        $identityTotals = [];
        $destinationTotals = [];
        foreach (E1IdentityState::cases() as $state) {
            $identityTotals[$state->value] = ['count' => 0, 'amount' => '0.00'];
        }
        foreach (E1MigrationDestinationState::cases() as $state) {
            $destinationTotals[$state->value] = ['count' => 0, 'amount' => '0.00'];
        }

        $rows = [];
        foreach ($manifest['rows'] as $row) {
            $resolved = $this->resolveRow($row);
            $amount = (string) ($row['refund_amount'] ?? $row['amount'] ?? '0');

            $identityTotals[$resolved['identity_state']->value]['count']++;
            $identityTotals[$resolved['identity_state']->value]['amount'] = bcadd(
                $identityTotals[$resolved['identity_state']->value]['amount'],
                $amount,
                2,
            );

            $destinationTotals[$resolved['destination_state']->value]['count']++;
            $destinationTotals[$resolved['destination_state']->value]['amount'] = bcadd(
                $destinationTotals[$resolved['destination_state']->value]['amount'],
                $amount,
                2,
            );

            $rows[] = [
                'refund_id' => (int) $row['refund_id'],
                'amount' => $amount,
                'site' => $row['site'] ?? null,
                'local_user_id' => $row['local_user_id'] ?? null,
                'identity_state' => $resolved['identity_state']->value,
                'destination_state' => $resolved['destination_state']->value,
                'desk_customer_id' => $resolved['desk_customer_id'],
                'cwid' => $resolved['cwid'],
                'blockers' => $resolved['blockers'],
                'destination_ready' => $resolved['destination_ready'],
            ];
        }

        return [
            'cohort_id' => $manifest['cohort_id'],
            'population' => [
                'count' => $manifest['refund_count'],
                'amount' => $manifest['amount'],
            ],
            'identity_totals' => $identityTotals,
            'destination_totals' => $destinationTotals,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{
     *     identity_state: E1IdentityState,
     *     destination_state: E1MigrationDestinationState,
     *     desk_customer_id: ?string,
     *     cwid: ?string,
     *     blockers: list<string>,
     *     destination_ready: bool
     * }
     */
    public function resolveRow(array $row): array
    {
        $refundId = (int) ($row['refund_id'] ?? 0);
        $site = strtolower(trim((string) ($row['site'] ?? '')));
        $localUserId = trim((string) ($row['local_user_id'] ?? ''));
        $blockers = [];

        $migration = CentralWalletRefundMigration::query()
            ->where('refund_id', $refundId)
            ->first();

        $metadata = is_array($migration?->metadata) ? $migration->metadata : [];
        $e1Meta = is_array($metadata['e1_verification'] ?? null) ? $metadata['e1_verification'] : [];

        if ($migration !== null
            && $migration->status === RefundMigrationStatus::Prepared
            && ($e1Meta['state'] ?? '') === E1MigrationDestinationState::MigrationDestinationReady->value
            && is_string($migration->desk_customer_id)
            && is_string($migration->cwid)) {
            $link = $this->findActiveLink($site, $localUserId, $migration->desk_customer_id, $migration->cwid);
            if ($link !== null) {
                return [
                    'identity_state' => E1IdentityState::TrustedExistingCwid,
                    'destination_state' => E1MigrationDestinationState::MigrationDestinationReady,
                    'desk_customer_id' => $migration->desk_customer_id,
                    'cwid' => $migration->cwid,
                    'blockers' => [],
                    'destination_ready' => true,
                ];
            }
            $blockers[] = 'prepared_without_active_link';
        }

        $link = CentralWalletAccountLink::query()
            ->where('site_code', $site)
            ->where('local_user_id', $localUserId)
            ->where('status', AccountLinkStatus::Active)
            ->first();

        if ($link !== null) {
            $conflictingLinks = CentralWalletAccountLink::query()
                ->where('site_code', $site)
                ->where('local_user_id', $localUserId)
                ->where('status', AccountLinkStatus::Active)
                ->where('id', '!=', $link->id)
                ->exists();

            if ($conflictingLinks) {
                return $this->ambiguous($blockers, ['multiple_active_links_same_site_user']);
            }

            if ($link->desk_customer_id === null) {
                return $this->verificationAvailable($blockers, ['active_link_missing_desk_customer']);
            }

            $customer = CentralCustomer::query()->find($link->desk_customer_id);
            if ($customer === null) {
                return $this->ambiguous($blockers, ['link_points_to_missing_customer']);
            }

            if ($link->central_wallet_id !== $customer->central_wallet_id) {
                return $this->ambiguous($blockers, ['link_cwid_customer_mismatch']);
            }

            if (! $this->hasTrustedCredential($customer->id)) {
                return $this->verificationAvailable($blockers, ['link_exists_trusted_credential_missing']);
            }

            return [
                'identity_state' => E1IdentityState::TrustedExistingCwid,
                'destination_state' => E1MigrationDestinationState::LinkReady,
                'desk_customer_id' => $customer->id,
                'cwid' => $customer->central_wallet_id,
                'blockers' => $blockers !== [] ? $blockers : ['journal_destination_not_prepared'],
                'destination_ready' => false,
            ];
        }

        $credentialCustomers = $this->customersWithTrustedCredentialsForSiteUser($site, $localUserId);
        if (count($credentialCustomers) > 1) {
            return $this->ambiguous($blockers, ['multiple_trusted_customers_for_site_user']);
        }

        if (count($credentialCustomers) === 1) {
            $customerId = $credentialCustomers[0];
            $customer = CentralCustomer::query()->find($customerId);
            if ($customer === null) {
                return $this->ambiguous($blockers, ['credential_customer_missing']);
            }

            return [
                'identity_state' => E1IdentityState::TrustedIdentityNoCwid,
                'destination_state' => E1MigrationDestinationState::CwidReady,
                'desk_customer_id' => $customer->id,
                'cwid' => $customer->central_wallet_id,
                'blockers' => ['active_site_account_link_missing'],
                'destination_ready' => false,
            ];
        }

        if ($site !== '' && $localUserId !== '') {
            return [
                'identity_state' => E1IdentityState::VerificationAvailable,
                'destination_state' => E1MigrationDestinationState::Unverified,
                'desk_customer_id' => null,
                'cwid' => null,
                'blockers' => ['trusted_verification_not_completed'],
                'destination_ready' => false,
            ];
        }

        return [
            'identity_state' => E1IdentityState::IdentityInsufficient,
            'destination_state' => E1MigrationDestinationState::Unverified,
            'desk_customer_id' => null,
            'cwid' => null,
            'blockers' => ['site_or_local_user_missing'],
            'destination_ready' => false,
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $reasons
     * @return array{
     *     identity_state: E1IdentityState,
     *     destination_state: E1MigrationDestinationState,
     *     desk_customer_id: null,
     *     cwid: null,
     *     blockers: list<string>,
     *     destination_ready: bool
     * }
     */
    private function ambiguous(array $blockers, array $reasons): array
    {
        return [
            'identity_state' => E1IdentityState::AmbiguousOrConflicting,
            'destination_state' => E1MigrationDestinationState::Ambiguous,
            'desk_customer_id' => null,
            'cwid' => null,
            'blockers' => array_values(array_unique(array_merge($blockers, $reasons))),
            'destination_ready' => false,
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $reasons
     * @return array{
     *     identity_state: E1IdentityState,
     *     destination_state: E1MigrationDestinationState,
     *     desk_customer_id: null,
     *     cwid: null,
     *     blockers: list<string>,
     *     destination_ready: bool
     * }
     */
    private function verificationAvailable(array $blockers, array $reasons): array
    {
        return [
            'identity_state' => E1IdentityState::VerificationAvailable,
            'destination_state' => E1MigrationDestinationState::Unverified,
            'desk_customer_id' => null,
            'cwid' => null,
            'blockers' => array_values(array_unique(array_merge($blockers, $reasons))),
            'destination_ready' => false,
        ];
    }

    private function hasTrustedCredential(string $deskCustomerId): bool
    {
        return CentralCustomerIdentityCredential::query()
            ->where('desk_customer_id', $deskCustomerId)
            ->whereIn('credential_type', self::TRUSTED_CREDENTIAL_TYPES)
            ->exists();
    }

    /**
     * @return list<string>
     */
    private function customersWithTrustedCredentialsForSiteUser(string $site, string $localUserId): array
    {
        return CentralWalletAccountLink::query()
            ->where('site_code', $site)
            ->where('local_user_id', $localUserId)
            ->where('status', AccountLinkStatus::Active)
            ->whereNotNull('desk_customer_id')
            ->pluck('desk_customer_id')
            ->unique()
            ->filter(fn (?string $id): bool => is_string($id) && $id !== '' && $this->hasTrustedCredential($id))
            ->values()
            ->all();
    }

    private function findActiveLink(
        string $site,
        string $localUserId,
        string $deskCustomerId,
        string $cwid,
    ): ?CentralWalletAccountLink {
        if ($site === '' || $localUserId === '') {
            return CentralWalletAccountLink::query()
                ->where('desk_customer_id', $deskCustomerId)
                ->where('central_wallet_id', $cwid)
                ->where('status', AccountLinkStatus::Active)
                ->first();
        }

        return CentralWalletAccountLink::query()
            ->where('site_code', $site)
            ->where('local_user_id', $localUserId)
            ->where('desk_customer_id', $deskCustomerId)
            ->where('central_wallet_id', $cwid)
            ->where('status', AccountLinkStatus::Active)
            ->first();
    }
}
