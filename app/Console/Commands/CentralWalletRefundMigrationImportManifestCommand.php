<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\RefundMigrationJournalImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('central-wallet:refund-migration-import-manifest
    {--manifest= : Optional manifest JSON path}')]
#[Description('Import the 292-refund migration manifest into the durable journal (no financial writes)')]
class CentralWalletRefundMigrationImportManifestCommand extends Command
{
    public function __construct(
        private readonly RefundMigrationJournalImportService $importService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $manifest = $this->option('manifest');
        $path = is_string($manifest) && $manifest !== '' ? $manifest : null;

        $result = $this->importService->import($path);

        $this->info('batch_id='.$result['batch_id']);
        $this->info('imported='.$result['imported']);
        $this->info('skipped='.$result['skipped']);

        return self::SUCCESS;
    }
}
