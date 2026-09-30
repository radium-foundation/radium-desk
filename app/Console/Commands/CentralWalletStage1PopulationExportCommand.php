<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\Stage1PopulationExportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('central-wallet:stage1-population-export
    {--cutoff=2026-07-15 00:00:00 : Wallet created_at cutoff in Asia/Kolkata}
    {--site=radiumbox.com : Optional site_code filter for account links}
    {--output= : Write JSON export to this path (stdout if omitted)}')]
#[Description('Read-only export of Desk Central Wallets created on/after the Stage 1 cutoff (hashed credentials only)')]
class CentralWalletStage1PopulationExportCommand extends Command
{
    public function __construct(
        private readonly Stage1PopulationExportService $exportService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $cutoff = (string) $this->option('cutoff');
        $site = (string) $this->option('site');
        $siteFilter = $site !== '' ? $site : null;

        $export = $this->exportService->export($cutoff, $siteFilter);
        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $outputPath = $this->option('output');
        if (is_string($outputPath) && $outputPath !== '') {
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, $json);
            $this->info('Wrote Stage 1 population export to '.$outputPath);
        } else {
            $this->line($json);
        }

        $summary = $export['summary'];
        $this->newLine();
        $this->line('wallet_rows='.($summary['wallet_rows'] ?? 0));
        $this->line('customers='.($summary['customers'] ?? 0));
        $this->line('customers_with_credentials='.($summary['customers_with_credentials'] ?? 0));
        $this->line('aggregate_spendable_balance='.($summary['aggregate_spendable_balance'] ?? '0.00'));

        return self::SUCCESS;
    }
}
