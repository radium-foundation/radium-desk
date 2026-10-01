<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\RefundMigrationDryRunService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('central-wallet:refund-migration-dry-run
    {--manifest= : Optional manifest JSON path}
    {--owner-approval-ref= : Optional owner approval ref for batch gate evaluation}
    {--output= : Write JSON report to this path}')]
#[Description('Production-safe dry run for the 292-refund Central Wallet migration (no financial writes)')]
class CentralWalletRefundMigrationDryRunCommand extends Command
{
    public function __construct(
        private readonly RefundMigrationDryRunService $dryRunService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $manifest = $this->option('manifest');
        $path = is_string($manifest) && $manifest !== '' ? $manifest : null;
        $ownerApprovalRef = $this->option('owner-approval-ref');
        $approval = is_string($ownerApprovalRef) && $ownerApprovalRef !== '' ? $ownerApprovalRef : null;

        $report = $this->dryRunService->run($approval, $path);
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $outputPath = $this->option('output');
        if (is_string($outputPath) && $outputPath !== '') {
            file_put_contents($outputPath, $json);
            $this->info('Wrote dry-run report to '.$outputPath);
        } else {
            $this->line($json);
        }

        $this->newLine();
        $this->line('journal_count='.$report['journal_count']);
        $this->line('journal_amount='.$report['journal_amount']);
        $this->line('financial_variance='.$report['financial_variance']);
        $this->line('pending_identity_count='.$report['pending_identity_count']);
        $this->line('pending_ambiguous_count='.$report['pending_ambiguous_count']);
        $this->line('batch_ready='.($report['batch_ready'] ? 'true' : 'false'));

        return self::SUCCESS;
    }
}
