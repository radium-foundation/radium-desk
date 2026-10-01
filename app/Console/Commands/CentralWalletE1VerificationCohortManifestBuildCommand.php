<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\E1CohortManifestLoader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class CentralWalletE1VerificationCohortManifestBuildCommand extends Command
{
    protected $signature = 'central-wallet:e1-verification-cohort-manifest-build
                            {--source= : Fresh reconciliation JSON path (classification G rows)}
                            {--output= : Output manifest path}
                            {--prompt-id= : Prompt ID metadata}';

    protected $description = 'Build immutable E-1 verification cohort manifest from reconciliation classification G';

    public function handle(): int
    {
        $sourcePath = $this->option('source');
        $source = is_string($sourcePath) && $sourcePath !== ''
            ? $sourcePath
            : storage_path('app/private/cw-remaining-239-fresh-reconciliation-p30-10-24.json');

        if (! File::exists($source)) {
            $this->error('source_reconciliation_not_found: '.$source);

            return self::FAILURE;
        }

        $decoded = json_decode(File::get($source), true);
        if (! is_array($decoded) || ! isset($decoded['rows'])) {
            $this->error('source_reconciliation_invalid');

            return self::FAILURE;
        }

        $rows = [];
        foreach ($decoded['rows'] as $row) {
            if (! is_array($row) || (string) ($row['classification'] ?? '') !== 'G') {
                continue;
            }

            $localUserId = trim((string) ($row['local_user_id'] ?? ''));
            if ($localUserId === '') {
                throw new InvalidArgumentException('e1_cohort_row_missing_local_user_id:'.($row['refund_id'] ?? '?'));
            }

            $rows[] = [
                'refund_id' => (int) $row['refund_id'],
                'refund_amount' => (string) $row['amount'],
                'desk_refund_reference' => (string) ($row['desk_refund_reference'] ?? ''),
                'site' => (string) ($row['site'] ?? ''),
                'local_user_id' => $localUserId,
                'order_number' => $row['order_number'] ?? null,
                'source_wallet_id' => $row['source_wallet_id'] ?? null,
                'source_application' => $row['source_application'] ?? ($row['site'] ?? null),
                'current_source_spendable_balance' => $row['current_source_spendable_balance'] ?? null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['refund_id'] <=> $b['refund_id']);

        $amount = '0.00';
        foreach ($rows as $row) {
            $amount = bcadd($amount, (string) $row['refund_amount'], 2);
        }

        $expectedCount = (int) config('central_wallet.e1_identity_migration.expected_count', E1CohortManifestLoader::EXPECTED_REFUNDS);
        $expectedAmount = (string) config('central_wallet.e1_identity_migration.expected_amount', E1CohortManifestLoader::EXPECTED_AMOUNT);

        if (count($rows) !== $expectedCount) {
            $this->error('e1_cohort_count_mismatch: expected '.$expectedCount.', got '.count($rows));

            return self::FAILURE;
        }

        if (bccomp($amount, $expectedAmount, 2) !== 0) {
            $this->error('e1_cohort_amount_mismatch: expected '.$expectedAmount.', got '.$amount);

            return self::FAILURE;
        }

        $promptId = $this->option('prompt-id');
        $manifest = [
            'cohort_id' => E1CohortManifestLoader::COHORT_ID,
            'prompt_id' => is_string($promptId) && $promptId !== '' ? $promptId : 'RadiumDesk-P-30-10-32',
            'generated_at' => now()->toIso8601String(),
            'refund_count' => count($rows),
            'refund_amount' => $amount,
            'manifest_rows_sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
            'rows' => $rows,
        ];

        $output = $this->option('output');
        $outputPath = is_string($output) && $output !== ''
            ? $output
            : (string) config(
                'central_wallet.e1_identity_migration.verification_cohort_manifest_path',
                storage_path('app/private/cw-e1-verification-cohort-manifest-p30-10-32.json'),
            );

        File::put($outputPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->info('E-1 verification cohort manifest built');
        $this->line('Path: '.$outputPath);
        $this->line('Population: '.count($rows).' / ₹'.$amount);
        $this->line('Manifest rows SHA-256: '.$manifest['manifest_rows_sha256']);

        return self::SUCCESS;
    }
}
