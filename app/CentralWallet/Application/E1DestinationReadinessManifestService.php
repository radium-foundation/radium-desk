<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class E1DestinationReadinessManifestService
{
    public function __construct(
        private readonly E1CohortManifestLoader $cohortManifestLoader,
        private readonly E1CohortStateResolver $cohortStateResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?string $cohortManifestPath = null, ?string $promptId = null): array
    {
        $cohortManifest = $this->cohortManifestLoader->load($cohortManifestPath);
        $audit = $this->cohortStateResolver->audit($cohortManifestPath);

        $readyRows = [];
        $blockedRows = [];

        foreach ($audit['rows'] as $row) {
            $refundId = (int) $row['refund_id'];
            $migration = CentralWalletRefundMigration::query()
                ->where('refund_id', $refundId)
                ->first();

            $metadata = is_array($migration?->metadata) ? $migration->metadata : [];
            $e1Meta = is_array($metadata['e1_verification'] ?? null) ? $metadata['e1_verification'] : [];

            $manifestRow = [
                'refund_id' => $refundId,
                'amount' => (string) $row['amount'],
                'site' => $row['site'],
                'local_user_id' => $row['local_user_id'],
                'identity_state' => (string) $row['identity_state'],
                'destination_state' => (string) $row['destination_state'],
                'destination_ready' => (bool) ($row['destination_ready'] ?? false),
                'desk_customer_id' => $row['desk_customer_id'],
                'cwid' => $row['cwid'],
                'verification_method' => $e1Meta['verification_method'] ?? null,
                'verification_timestamp' => $e1Meta['prepared_at'] ?? null,
                'evidence_reference' => $e1Meta['evidence'] ?? null,
                'journal_status' => $migration?->status?->value,
                'journal_batch_id' => $migration?->batch_id,
                'idempotency_key' => $migration?->idempotency_key,
                'blockers' => $row['blockers'],
                'financial_execution' => false,
            ];

            if ($manifestRow['destination_ready']) {
                $readyRows[] = $manifestRow;
            } else {
                $blockedRows[] = array_merge($manifestRow, [
                    'blocked_reason' => implode(';', $row['blockers'] ?? []) ?: 'not_destination_ready',
                ]);
            }
        }

        usort($readyRows, static fn (array $a, array $b): int => $a['refund_id'] <=> $b['refund_id']);
        usort($blockedRows, static fn (array $a, array $b): int => $a['refund_id'] <=> $b['refund_id']);

        $readyAmount = '0.00';
        foreach ($readyRows as $r) {
            $readyAmount = bcadd($readyAmount, (string) $r['amount'], 2);
        }
        $blockedAmount = '0.00';
        foreach ($blockedRows as $r) {
            $blockedAmount = bcadd($blockedAmount, (string) $r['amount'], 2);
        }

        $allRows = array_merge($readyRows, $blockedRows);
        $rowsHash = hash('sha256', json_encode($allRows, JSON_THROW_ON_ERROR));

        $ledgerCount = CentralWalletLedgerEntry::query()
            ->where('entry_type', LedgerEntryType::Credit->value)
            ->count();
        $ledgerAmount = (string) CentralWalletLedgerEntry::query()
            ->where('entry_type', LedgerEntryType::Credit->value)
            ->sum('amount');
        $reconciled = CentralWalletRefundMigration::query()
            ->where('status', RefundMigrationStatus::Reconciled)
            ->count();

        return [
            'prompt_id' => $promptId ?? 'RadiumDesk-P-30-10-32',
            'generated_at' => now()->toIso8601String(),
            'mode' => 'e1_destination_readiness_read_only',
            'financial_execution' => false,
            'cohort_id' => $cohortManifest['cohort_id'],
            'population' => [
                'count' => $cohortManifest['refund_count'],
                'amount' => $cohortManifest['amount'],
            ],
            'identity_totals' => $audit['identity_totals'],
            'destination_totals' => $audit['destination_totals'],
            'destination_ready_count' => count($readyRows),
            'destination_ready_amount' => $readyAmount,
            'blocked_count' => count($blockedRows),
            'blocked_amount' => $blockedAmount,
            'manifest_rows_sha256' => $rowsHash,
            'financial_zero_check' => [
                'ledger_credits' => ['count' => $ledgerCount, 'amount' => bcadd($ledgerAmount, '0', 2)],
                'reconciled_journal_count' => $reconciled,
            ],
            'destination_ready_rows' => $readyRows,
            'blocked_rows' => $blockedRows,
        ];
    }

    /**
     * @return array{path: string, manifest: array<string, mixed>}
     */
    public function write(?string $outputPath = null, ?string $cohortManifestPath = null, ?string $promptId = null): array
    {
        $manifest = $this->build($cohortManifestPath, $promptId);
        $path = $outputPath ?? (string) config(
            'central_wallet.e1_identity_migration.destination_readiness_manifest_path',
            storage_path('app/private/cw-e1-destination-readiness-manifest-p30-10-32.json'),
        );

        if ($path === '') {
            throw new InvalidArgumentException('e1_destination_readiness_manifest_path_required');
        }

        File::put($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return ['path' => $path, 'manifest' => $manifest];
    }
}
