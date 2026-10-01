<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\E2SettlementDestinationState;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class E2DestinationReadinessManifestService
{
    public function __construct(
        private readonly E2CohortManifestLoader $cohortManifestLoader,
        private readonly E2CohortStateResolver $cohortStateResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?string $cohortManifestPath = null, ?string $promptId = null): array
    {
        $cohortManifest = $this->cohortManifestLoader->load($cohortManifestPath);
        $audit = $this->cohortStateResolver->audit($cohortManifestPath);

        $rows = [];
        foreach ($audit['rows'] as $row) {
            $refundId = (int) $row['refund_id'];
            $migration = CentralWalletRefundMigration::query()
                ->where('refund_id', $refundId)
                ->first();

            $metadata = is_array($migration?->metadata) ? $migration->metadata : [];
            $e2Meta = is_array($metadata['e2_verification'] ?? null) ? $metadata['e2_verification'] : [];

            $rows[] = [
                'refund_id' => $refundId,
                'refund_amount' => (string) $row['amount'],
                'site' => $row['site'],
                'destination_state' => (string) $row['state'],
                'destination_ready' => $row['state'] === E2SettlementDestinationState::SettlementDestinationReady->value,
                'desk_customer_id' => $row['desk_customer_id'],
                'cwid' => $row['cwid'],
                'local_user_id' => $row['local_user_id'],
                'verification_status' => $this->verificationStatusLabel((string) $row['state']),
                'verification_method' => $e2Meta['verification_method'] ?? null,
                'verification_timestamp' => $e2Meta['prepared_at'] ?? null,
                'journal_status' => $migration?->status?->value,
                'journal_batch_id' => $migration?->batch_id,
                'blockers' => $row['blockers'],
                'source_wallet_provenance' => 'unavailable_not_reconstructed',
                'lane4_settlement_executed' => false,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['refund_id'] <=> $b['refund_id']);

        $rowsHash = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));

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
            'prompt_id' => $promptId ?? 'RadiumDesk-P-30-10-29',
            'generated_at' => now()->toIso8601String(),
            'mode' => 'e2_destination_readiness_read_only',
            'financial_execution' => false,
            'cohort_id' => $cohortManifest['cohort_id'],
            'verification_cohort_manifest_sha256' => E2CohortManifestLoader::VERIFICATION_COHORT_MANIFEST_SHA256,
            'population' => [
                'count' => $cohortManifest['refund_count'],
                'amount' => $cohortManifest['amount'],
            ],
            'destination_totals' => $audit['totals'],
            'destination_ready_count' => $audit['totals'][E2SettlementDestinationState::SettlementDestinationReady->value]['count'] ?? 0,
            'destination_ready_amount' => $audit['totals'][E2SettlementDestinationState::SettlementDestinationReady->value]['amount'] ?? '0.00',
            'manifest_rows_sha256' => $rowsHash,
            'financial_zero_check' => [
                'ledger_credits' => ['count' => $ledgerCount, 'amount' => bcadd($ledgerAmount, '0', 2)],
                'reconciled_journal_count' => $reconciled,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{path: string, manifest: array<string, mixed>}
     */
    public function write(?string $outputPath = null, ?string $cohortManifestPath = null, ?string $promptId = null): array
    {
        $manifest = $this->build($cohortManifestPath, $promptId);
        $path = $outputPath ?? (string) config(
            'central_wallet.e2_historical_settlement.destination_readiness_manifest_path',
            storage_path('app/private/cw-e2-destination-readiness-manifest-p30-10-29.json'),
        );

        if ($path === '') {
            throw new InvalidArgumentException('e2_destination_readiness_manifest_path_required');
        }

        File::put($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return ['path' => $path, 'manifest' => $manifest];
    }

    private function verificationStatusLabel(string $state): string
    {
        return match ($state) {
            E2SettlementDestinationState::SettlementDestinationReady->value => 'destination_ready',
            E2SettlementDestinationState::Ambiguous->value => 'ambiguous',
            E2SettlementDestinationState::Unverified->value => 'unverified',
            default => 'verification_in_progress',
        };
    }
}
