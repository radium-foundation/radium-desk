<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\Ready4RefundMigrationRehearseService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('central-wallet:ready4-refund-migration-rehearse
    {--manifest= : Optional manifest JSON path}
    {--output= : Write JSON report to this path}')]
#[Description('Non-writing executor rehearsal for the READY-4 refund preflight cohort')]
class CentralWalletReady4RefundMigrationRehearseCommand extends Command
{
    public function __construct(
        private readonly Ready4RefundMigrationRehearseService $rehearseService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $manifest = $this->option('manifest');
        $path = is_string($manifest) && $manifest !== '' ? $manifest : null;

        $report = $this->rehearseService->run($path);
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $outputPath = $this->option('output');
        if (is_string($outputPath) && $outputPath !== '') {
            file_put_contents($outputPath, $json);
            $this->info('Wrote rehearsal report to '.$outputPath);
        } else {
            $this->line($json);
        }

        $this->newLine();
        $this->line('rehearsal_pass='.($report['rehearsal_pass'] ? 'true' : 'false'));
        $this->line('executable_rehearsal_pass='.($report['executable_rehearsal_pass'] ? 'true' : 'false'));

        return ($report['executable_rehearsal_pass'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
