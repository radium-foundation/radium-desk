<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Domain\RefundMigrationIdempotencyKey;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class Refund360MigrationJournalImportService
{
    public function __construct(
        private readonly Refund360MigrationManifestLoader $manifestLoader,
    ) {}

    /**
     * @return array{imported: int, skipped: int, batch_id: string, assigned: int, blocked_manifest: int}
     */
    public function import(?string $manifestPath = null, bool $verifyLiveSpokeBalances = false): array
    {
        $manifest = $this->manifestLoader->load($manifestPath);
        $rows = $this->manifestLoader->executableRows($manifestPath);
        $batchId = (string) $manifest['batch_id'];
        $imported = 0;
        $skipped = 0;
        $assigned = 0;
        $blockedManifest = count($manifest['blocked_rows'] ?? []);

        if ($verifyLiveSpokeBalances) {
            $this->verifyLiveSpokeBalances($rows);
        }

        DB::transaction(function () use ($rows, $batchId, &$imported, &$skipped, &$assigned): void {
            foreach ($rows as $row) {
                $refundId = (int) $row['refund_id'];
                $existing = CentralWalletRefundMigration::query()
                    ->where('refund_id', $refundId)
                    ->first();

                if ($existing !== null) {
                    if ($existing->batch_id !== $batchId) {
                        throw new InvalidArgumentException('refund360_refund_already_imported_in_other_batch:'.$refundId);
                    }

                    $skipped++;

                    continue;
                }

                $deskCustomerId = (string) $row['desk_customer_id'];
                $cwid = (string) $row['cwid'];
                $this->assertCustomerWalletLink($deskCustomerId, $cwid);
                $this->assertActiveAccountLink($row);

                $sourceWalletId = (int) $row['source_wallet_id'];
                $amount = (string) $row['refund_amount'];

                CentralWalletRefundMigration::query()->create([
                    'id' => (string) Str::uuid(),
                    'batch_id' => $batchId,
                    'refund_id' => $refundId,
                    'refund_reference' => (string) $row['desk_refund_reference'],
                    'amount' => $amount,
                    'source_type' => 'spoke_wallet',
                    'source_application' => (string) ($row['site'] ?? 'rdservice.in'),
                    'source_wallet_id' => $sourceWalletId,
                    'source_reference' => RefundMigrationIdempotencyKey::ledgerSourceReference($refundId),
                    'desk_customer_id' => $deskCustomerId,
                    'cwid' => $cwid,
                    'lane' => RefundMigrationLane::Lane1SpokeCutover,
                    'status' => RefundMigrationStatus::Prepared,
                    'idempotency_key' => RefundMigrationIdempotencyKey::forRefund($refundId),
                    'order_number' => isset($row['order_number']) ? (string) $row['order_number'] : null,
                    'identity_class' => 'C',
                    'prepared_at' => now(),
                    'metadata' => [
                        'refund360_cohort_id' => Refund360MigrationManifestLoader::COHORT_ID,
                        'local_user_id' => $row['local_user_id'] ?? null,
                        'order_resolved_user_id' => $row['local_user_id'] ?? null,
                        'source_balance_before' => $row['source_balance_before'] ?? null,
                        'match_method' => $row['match_method'] ?? null,
                        'rollback_action' => $row['rollback_action'] ?? null,
                        'rollback_idempotency_key' => $row['rollback_idempotency_key'] ?? null,
                        'manifest_rows_sha256' => Refund360MigrationManifestLoader::MANIFEST_ROWS_SHA256,
                        'migration_lane' => 'LANE_1_SPOKE_CUTOVER',
                    ],
                ]);

                $imported++;
                $assigned++;
            }
        });

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'assigned' => $assigned,
            'blocked_manifest' => $blockedManifest,
            'batch_id' => $batchId,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertActiveAccountLink(array $row): void
    {
        $link = CentralWalletAccountLink::query()
            ->where('site_code', (string) ($row['site'] ?? 'rdservice.in'))
            ->where('local_user_id', (string) ($row['local_user_id'] ?? ''))
            ->where('desk_customer_id', (string) $row['desk_customer_id'])
            ->where('central_wallet_id', (string) $row['cwid'])
            ->where('status', 'active')
            ->first();

        if ($link === null) {
            throw new InvalidArgumentException('refund360_migration_account_link_missing');
        }
    }

    private function assertCustomerWalletLink(string $deskCustomerId, string $cwid): void
    {
        $customer = CentralCustomer::query()->find($deskCustomerId);
        if ($customer === null) {
            throw new InvalidArgumentException('desk_customer_not_found');
        }

        if ($customer->central_wallet_id !== $cwid) {
            throw new InvalidArgumentException('cwid_does_not_belong_to_customer');
        }

        if (CentralWallet::query()->find($cwid) === null) {
            throw new InvalidArgumentException('cwid_not_found');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function verifyLiveSpokeBalances(array $rows): void
    {
        $balances = $this->fetchRadiumboxWalletBalances();

        foreach ($rows as $row) {
            $walletId = (int) ($row['source_wallet_id'] ?? 0);
            $amount = (string) ($row['refund_amount'] ?? '');
            $expected = (string) ($row['source_balance_before'] ?? '');
            $live = $balances[$walletId] ?? null;

            if ($live === null) {
                throw new InvalidArgumentException('spoke_wallet_not_found:'.$walletId);
            }

            if (bccomp($live, $amount, 2) !== 0 || bccomp($live, $expected, 2) !== 0) {
                throw new InvalidArgumentException(
                    'spoke_balance_mismatch:wallet='.$walletId.':live='.$live.':expected='.$expected,
                );
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function fetchRadiumboxWalletBalances(): array
    {
        $envPath = (string) config('central_wallet.refund360_migration.radiumbox_env_path', '');
        if ($envPath === '' || ! is_readable($envPath)) {
            throw new InvalidArgumentException('radiumbox_env_path_unavailable_for_live_balance_verification');
        }

        $password = trim((string) shell_exec('grep ^DB_PASSWORD= '.escapeshellarg($envPath).' | cut -d= -f2-'));
        $user = (string) config('central_wallet.refund360_migration.radiumbox_db_user', 'radiumbox_prod');
        $database = (string) config('central_wallet.refund360_migration.radiumbox_db_name', 'radiumbox_prod');

        $command = sprintf(
            'mysql -u%s -p%s %s -N -B -e %s 2>/dev/null',
            escapeshellarg($user),
            escapeshellarg($password),
            escapeshellarg($database),
            escapeshellarg('SELECT id, COALESCE(credit,0) FROM users_wallet;'),
        );

        $output = shell_exec($command);
        if (! is_string($output) || trim($output) === '') {
            throw new InvalidArgumentException('radiumbox_wallet_balance_query_failed');
        }

        $balances = [];
        foreach (explode("\n", trim($output)) as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) < 2) {
                continue;
            }
            $balances[(int) $parts[0]] = (string) $parts[1];
        }

        return $balances;
    }
}
