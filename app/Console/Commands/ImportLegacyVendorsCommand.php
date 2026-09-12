<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Purchasing\LegacyVendorImportService;
use Illuminate\Console\Command;

class ImportLegacyVendorsCommand extends Command
{
    protected $signature = 'purchasing:import-legacy-vendors
        {path : Path to JSON fixture of legacy supplier rows}
        {--batch= : Optional batch reference}
        {--user=1 : Desk user ID to attribute the import to}';

    protected $description = 'Import legacy Admin supplier master rows into Desk vendors (idempotent, non-production safe when run locally).';

    public function handle(LegacyVendorImportService $importService): int
    {
        $path = $this->argument('path');
        if (! is_readable($path)) {
            $this->error("Fixture not readable: {$path}");

            return self::FAILURE;
        }

        $user = User::query()->find((int) $this->option('user'));
        if ($user === null) {
            $this->error('User not found for --user option.');

            return self::FAILURE;
        }

        $result = $importService->importFromJson($path, $user, $this->option('batch'));

        $this->info("Batch: {$result['batch']->batch_reference}");
        $this->line("Imported: {$result['imported']}");
        $this->line("Skipped: {$result['skipped']}");
        $this->line("Conflicted: {$result['conflicted']}");

        return self::SUCCESS;
    }
}
