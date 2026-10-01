<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\RefundMigrationRollbackService;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Signature('central-wallet:refund-migration-rollback
    {refund-id : Refund request ID}
    {--owner-approval-ref= : Required owner approval reference}
    {--force : Acknowledge rollback intent}')]
#[Description('Rollback a completed refund migration using ledger reversal (and spoke restore for Lane 1)')]
class CentralWalletRefundMigrationRollbackCommand extends Command
{
    public function __construct(
        private readonly RefundMigrationRollbackService $rollbackService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to rollback without --force acknowledgement.');

            return self::FAILURE;
        }

        $ownerApprovalRef = (string) $this->option('owner-approval-ref');
        if (trim($ownerApprovalRef) === '') {
            $this->error('--owner-approval-ref is required.');

            return self::FAILURE;
        }

        $migration = CentralWalletRefundMigration::query()
            ->where('refund_id', (int) $this->argument('refund-id'))
            ->firstOrFail();

        try {
            $result = $this->rollbackService->rollback(
                $migration,
                $ownerApprovalRef,
                (string) Str::uuid(),
                'refund_migration_rollback_command',
            );
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
