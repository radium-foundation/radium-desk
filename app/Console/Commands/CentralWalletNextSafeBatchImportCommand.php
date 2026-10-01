<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\NextSafeBatchJournalImportService;
use Illuminate\Console\Command;

class CentralWalletNextSafeBatchImportCommand extends Command
{
    protected $signature = 'central-wallet:next-safe-batch-import
                            {--manifest= : Optional manifest path}';

    protected $description = 'Import next-safe batch journal rows (P-30-10-24, preparation only)';

    public function handle(NextSafeBatchJournalImportService $importService): int
    {
        if ((bool) config('central_wallet.refund_migration.execution_enabled', false)) {
            $this->error('STOP: CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED is not false');

            return self::FAILURE;
        }

        $result = $importService->import($this->option('manifest'));

        $this->info('Batch: '.$result['batch_id']);
        $this->line('Imported: '.$result['imported']);
        $this->line('Skipped: '.$result['skipped']);
        $this->line('Prepared: '.$result['prepared']);

        return self::SUCCESS;
    }
}
