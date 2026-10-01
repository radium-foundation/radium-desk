<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\Refund360MigrationDryRunService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('central-wallet:refund360-migration-dry-run
    {--manifest= : Optional manifest JSON path}
    {--output= : Write JSON report to this path}')]
#[Description('Dry-run refund 360 radiumbox.com migration batch readiness (no financial writes)')]
class CentralWalletRefund360MigrationDryRunCommand extends Command
{
    public function __construct(
        private readonly Refund360MigrationDryRunService $dryRunService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $manifest = $this->option('manifest');
        $path = is_string($manifest) && $manifest !== '' ? $manifest : null;

        $report = $this->dryRunService->run($path);
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $outputPath = $this->option('output');
        if (is_string($outputPath) && $outputPath !== '') {
            file_put_contents($outputPath, $json);
            $this->info('Wrote dry-run report to '.$outputPath);
        } else {
            $this->line($json);
        }

        $this->newLine();
        $this->line('manifest_count='.$report['manifest_count']);
        $this->line('manifest_amount='.$report['manifest_amount']);
        $this->line('executable_manifest_count='.$report['executable_manifest_count']);
        $this->line('blocked_manifest_count='.$report['blocked_manifest_count']);
        $this->line('journal_count='.$report['journal_count']);
        $this->line('journal_amount='.$report['journal_amount']);
        $this->line('ready_count='.$report['ready_count']);
        $this->line('executable_batch_ready='.($report['executable_batch_ready'] ? 'true' : 'false'));

        return ($report['executable_batch_ready'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
