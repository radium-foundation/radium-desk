<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\E1DestinationReadinessManifestService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class CentralWalletE1DestinationReadinessManifestBuildCommand extends Command
{
    protected $signature = 'central-wallet:e1-destination-readiness-manifest-build
                            {--cohort-manifest= : Optional E-1 verification cohort manifest path}
                            {--output= : Output JSON path}
                            {--prompt-id= : Prompt ID for manifest metadata}';

    protected $description = 'Build read-only E-1 per-refund destination-readiness manifest (no financial writes)';

    public function handle(E1DestinationReadinessManifestService $service): int
    {
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
        $this->info('E-1 destination-readiness manifest built (read-only)');
        $this->line('Path: '.$result['path']);
        $this->line('Population: '.$manifest['population']['count'].' / ₹'.$manifest['population']['amount']);
        $this->line('Destination-ready: '.$manifest['destination_ready_count'].' / ₹'.$manifest['destination_ready_amount']);
        $this->line('Blocked: '.$manifest['blocked_count'].' / ₹'.$manifest['blocked_amount']);
        $this->line('Manifest rows SHA-256: '.$manifest['manifest_rows_sha256']);

        return self::SUCCESS;
    }
}
