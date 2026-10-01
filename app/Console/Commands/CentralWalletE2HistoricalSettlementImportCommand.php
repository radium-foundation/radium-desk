<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\E2HistoricalSettlementJournalImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('central-wallet:e2-historical-settlement-import
    {--manifest= : Optional manifest JSON path}')]
#[Description('Import the 52-row E-2 historical manual refund settlement manifest into the journal')]
class CentralWalletE2HistoricalSettlementImportCommand extends Command
{
    public function __construct(
        private readonly E2HistoricalSettlementJournalImportService $importService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $manifest = $this->option('manifest');
        $path = is_string($manifest) && $manifest !== '' ? $manifest : null;

        try {
            $result = $this->importService->import($path);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
