<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\E2HistoricalSettlementDryRunService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('central-wallet:e2-historical-settlement-dry-run
    {--manifest= : Optional manifest JSON path}
    {--owner-approval-ref= : Owner approval reference for gate evaluation}')]
#[Description('Dry-run the 52-row E-2 historical manual refund settlement batch')]
class CentralWalletE2HistoricalSettlementDryRunCommand extends Command
{
    public function __construct(
        private readonly E2HistoricalSettlementDryRunService $dryRunService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $manifest = $this->option('manifest');
        $path = is_string($manifest) && $manifest !== '' ? $manifest : null;
        $ownerApprovalRef = $this->option('owner-approval-ref');
        $ref = is_string($ownerApprovalRef) && $ownerApprovalRef !== '' ? $ownerApprovalRef : null;

        $result = $this->dryRunService->run($path, $ref);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return ($result['batch_executable'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
