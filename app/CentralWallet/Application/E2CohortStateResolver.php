<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Domain\Enums\E2SettlementDestinationState;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use InvalidArgumentException;

final class E2CohortStateResolver
{
    public function __construct(
        private readonly E2CohortManifestLoader $cohortManifestLoader,
    ) {}

    /**
     * @return array{
     *     cohort_id: string,
     *     population: array{count: int, amount: string},
     *     totals: array<string, array{count: int, amount: string}>,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function audit(?string $manifestPath = null): array
    {
        $manifest = $this->cohortManifestLoader->load($manifestPath);

        $totals = [];
        foreach (E2SettlementDestinationState::cases() as $state) {
            $totals[$state->value] = ['count' => 0, 'amount' => '0.00'];
        }

        $rows = [];
        foreach ($manifest['rows'] as $row) {
            $resolved = $this->resolveRowState($row);
            $amount = (string) ($row['refund_amount'] ?? $row['amount'] ?? '0');
            $state = $resolved['state']->value;

            $totals[$state]['count']++;
            $totals[$state]['amount'] = bcadd($totals[$state]['amount'], $amount, 2);

            $rows[] = [
                'refund_id' => (int) $row['refund_id'],
                'amount' => $amount,
                'site' => $row['site'] ?? null,
                'state' => $state,
                'desk_customer_id' => $resolved['desk_customer_id'],
                'cwid' => $resolved['cwid'],
                'local_user_id' => $resolved['local_user_id'],
                'blockers' => $resolved['blockers'],
            ];
        }

        return [
            'cohort_id' => $manifest['cohort_id'],
            'population' => [
                'count' => $manifest['refund_count'],
                'amount' => $manifest['amount'],
            ],
            'totals' => $totals,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{
     *     state: E2SettlementDestinationState,
     *     desk_customer_id: ?string,
     *     cwid: ?string,
     *     local_user_id: ?string,
     *     blockers: list<string>
     * }
     */
    public function resolveRowState(array $row): array
    {
        $refundId = (int) ($row['refund_id'] ?? 0);
        $site = strtolower(trim((string) ($row['site'] ?? '')));
        $emailHash = (string) ($row['order_email_hash'] ?? '');
        $blockers = [];

        $migration = CentralWalletRefundMigration::query()
            ->where('refund_id', $refundId)
            ->first();

        if ($migration !== null
            && $migration->status === RefundMigrationStatus::Prepared
            && is_string($migration->desk_customer_id)
            && is_string($migration->cwid)) {
            $link = $this->findActiveLink($site, $migration->desk_customer_id, $migration->cwid);
            if ($link !== null) {
                return [
                    'state' => E2SettlementDestinationState::SettlementDestinationReady,
                    'desk_customer_id' => $migration->desk_customer_id,
                    'cwid' => $migration->cwid,
                    'local_user_id' => $link->local_user_id,
                    'blockers' => [],
                ];
            }
            $blockers[] = 'prepared_without_active_link';
        }

        $credentialMatches = CentralCustomerIdentityCredential::query()
            ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
            ->where('subject_hash', $emailHash)
            ->get();

        if ($credentialMatches->pluck('desk_customer_id')->unique()->count() > 1) {
            return [
                'state' => E2SettlementDestinationState::Ambiguous,
                'desk_customer_id' => null,
                'cwid' => null,
                'local_user_id' => null,
                'blockers' => ['multiple_customers_share_email_hash'],
            ];
        }

        $credential = $credentialMatches->first();
        if ($credential === null) {
            return [
                'state' => E2SettlementDestinationState::Unverified,
                'desk_customer_id' => null,
                'cwid' => null,
                'local_user_id' => null,
                'blockers' => ['trusted_identity_not_established'],
            ];
        }

        $customer = CentralCustomer::query()->find($credential->desk_customer_id);
        if ($customer === null) {
            return [
                'state' => E2SettlementDestinationState::VerifiedIdentity,
                'desk_customer_id' => $credential->desk_customer_id,
                'cwid' => null,
                'local_user_id' => null,
                'blockers' => ['desk_customer_missing'],
            ];
        }

        $link = CentralWalletAccountLink::query()
            ->where('site_code', $site)
            ->where('desk_customer_id', $customer->id)
            ->where('central_wallet_id', $customer->central_wallet_id)
            ->where('status', AccountLinkStatus::Active)
            ->first();

        if ($link === null) {
            return [
                'state' => E2SettlementDestinationState::CwidReady,
                'desk_customer_id' => $customer->id,
                'cwid' => $customer->central_wallet_id,
                'local_user_id' => null,
                'blockers' => ['active_site_link_missing'],
            ];
        }

        return [
            'state' => E2SettlementDestinationState::LinkReady,
            'desk_customer_id' => $customer->id,
            'cwid' => $customer->central_wallet_id,
            'local_user_id' => $link->local_user_id,
            'blockers' => $blockers !== [] ? $blockers : ['journal_destination_not_prepared'],
        ];
    }

    private function findActiveLink(string $site, string $deskCustomerId, string $cwid): ?CentralWalletAccountLink
    {
        if ($site === '') {
            return null;
        }

        return CentralWalletAccountLink::query()
            ->where('site_code', $site)
            ->where('desk_customer_id', $deskCustomerId)
            ->where('central_wallet_id', $cwid)
            ->where('status', AccountLinkStatus::Active)
            ->first();
    }
}
