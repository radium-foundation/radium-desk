<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\Ready4RefundMigrationOrchestrator;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('central-wallet:ready4-refund-migration-execute
    {--owner-approval-ref= : Required owner approval reference}
    {--refund-id= : Required refund migration row ID (268, 284, or 336 only)}
    {--manifest= : Optional manifest JSON path}
    {--force : Acknowledge execution intent (still requires execution flag)}')]
#[Description('Execute a single READY-3 Central Wallet migration row (gated — default OFF)')]
class CentralWalletReady4RefundMigrationExecuteCommand extends Command
{
    public function __construct(
        private readonly Ready4RefundMigrationOrchestrator $orchestrator,
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

        $refundId = $this->option('refund-id');
        if (! is_string($refundId) || $refundId === '') {
            $this->error('--refund-id is required (268, 284, or 336).');

            return self::FAILURE;
        }

        $refundIdInt = (int) $refundId;
        if (! in_array($refundIdInt, Ready4RefundMigrationOrchestrator::EXECUTABLE_REFUND_IDS, true)) {
            $this->error('Refund ID '.$refundIdInt.' is not in the Ready3 executable allowlist.');

            return self::FAILURE;
        }

        $manifest = $this->option('manifest');
        $path = is_string($manifest) && $manifest !== '' ? $manifest : null;

        try {
            $migration = CentralWalletRefundMigration::query()
                ->where('refund_id', $refundIdInt)
                ->where('batch_id', 'desk-refund-wallet-migration-type1-ready4-p30-10-12')
                ->firstOrFail();

            $result = $this->orchestrator->executeSingle($migration, $ownerApprovalRef, null, 'ready4_refund_migration_execute_command', $path);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
