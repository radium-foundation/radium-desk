<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\PilotRefundMigrationJournalImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Description('Import a single-row pilot refund migration journal from an immutable manifest (no financial execution)')]
class CentralWalletPilotRefundMigrationImportCommand extends Command
{
    protected $signature = 'central-wallet:pilot-refund-migration-import
        {--manifest= : Optional manifest JSON path}
        {--confirm= : Required confirmation token matching CENTRAL_WALLET_PILOT_REFUND_MIGRATION_IMPORT_CONFIRM}';

    public function __construct(
        private readonly PilotRefundMigrationJournalImportService $importService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $expected = trim((string) config('central_wallet.pilot_refund_migration.import_confirm_token', ''));
        $provided = trim((string) $this->option('confirm'));

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            $this->error('Refusing import without a valid --confirm token.');

            return self::FAILURE;
        }

        $manifest = $this->option('manifest');
        $path = is_string($manifest) && $manifest !== '' ? $manifest : null;

        try {
            $result = $this->importService->import($path);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
