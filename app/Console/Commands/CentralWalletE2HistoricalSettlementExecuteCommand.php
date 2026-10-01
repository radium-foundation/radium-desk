<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\E2HistoricalSettlementOrchestrator;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('central-wallet:e2-historical-settlement-execute
    {--owner-approval-ref= : Required owner approval reference}
    {--refund-id= : Execute a single settlement row}
    {--manifest= : Optional manifest JSON path}
    {--force : Acknowledge execution intent (still requires execution flag)}')]
#[Description('Execute the 52-row E-2 historical manual refund settlement batch (gated — default OFF)')]
class CentralWalletE2HistoricalSettlementExecuteCommand extends Command
{
    public function __construct(
        private readonly E2HistoricalSettlementOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to execute without --force acknowledgement.');

            return self::FAILURE;
        }

        $ownerApprovalRef = (string) $this->option('owner-approval-ref');
        if (trim($ownerApprovalRef) === '') {
            $this->error('--owner-approval-ref is required.');

            return self::FAILURE;
        }

        $manifest = $this->option('manifest');
        $path = is_string($manifest) && $manifest !== '' ? $manifest : null;
        $refundId = $this->option('refund-id');

        try {
            if (is_string($refundId) && $refundId !== '') {
                $migration = CentralWalletRefundMigration::query()
                    ->where('refund_id', (int) $refundId)
                    ->firstOrFail();
                $result = $this->orchestrator->executeSingle($migration, $ownerApprovalRef);
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } else {
                $result = $this->orchestrator->executeBatch($ownerApprovalRef, $path);
                $this->line('processed='.$result['processed']);
            }
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
