<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\E2DestinationReadinessManifestService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class CentralWalletE2DestinationReadinessManifestBuildCommand extends Command
{
    protected $signature = 'central-wallet:e2-destination-readiness-manifest-build
                            {--cohort-manifest= : Optional E-2 verification cohort manifest path}
                            {--output= : Output JSON path}
                            {--prompt-id= : Prompt ID for manifest metadata}';

    protected $description = 'Build read-only E-2 per-refund destination-readiness manifest (no financial writes)';

    public function handle(E2DestinationReadinessManifestService $service): int
    {
        if ((bool) config('central_wallet.e2_historical_settlement.execution_enabled', false)) {
            $this->error('STOP: CENTRAL_WALLET_E2_HISTORICAL_SETTLEMENT_EXECUTION_ENABLED is not false');

            return self::FAILURE;
        }

        if ((bool) config('central_wallet.refund_migration.execution_enabled', false)) {
            $this->error('STOP: CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED is not false');

            return self::FAILURE;
        }

        $cohortManifest = $this->option('cohort-manifest');
        $cohortPath = is_string($cohortManifest) && $cohortManifest !== '' ? $cohortManifest : null;
        $output = $this->option('output');
        $outputPath = is_string($output) && $output !== '' ? $output : null;
        $promptId = $this->option('prompt-id');
        $prompt = is_string($promptId) && $promptId !== '' ? $promptId : null;

        try {
            $result = $service->write($outputPath, $cohortPath, $prompt);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $manifest = $result['manifest'];
        $this->info('E-2 destination-readiness manifest built (read-only)');
        $this->line('Path: '.$result['path']);
        $this->line('Population: '.$manifest['population']['count'].' / ₹'.$manifest['population']['amount']);
        $this->line('Destination-ready: '.$manifest['destination_ready_count'].' / ₹'.$manifest['destination_ready_amount']);
        $this->line('Manifest rows SHA-256: '.$manifest['manifest_rows_sha256']);
        $this->line('Verification cohort manifest SHA-256: '.$manifest['verification_cohort_manifest_sha256']);

        return self::SUCCESS;
    }
}
