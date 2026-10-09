<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\PilotRefundMigrationManifestLoader;
use App\CentralWallet\Application\RefundMigrationRollbackService;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Description('Rollback a reconciled pilot Central Wallet refund migration (gated)')]
class CentralWalletPilotRefundMigrationRollbackCommand extends Command
{
    protected $signature = 'central-wallet:pilot-refund-migration-rollback
        {--owner-approval-ref= : Required owner approval reference}
        {--refund-id= : Required refund id}
        {--manifest= : Optional manifest JSON path}
        {--confirm= : Required; must match CENTRAL_WALLET_PILOT_REFUND_MIGRATION_ROLLBACK_CONFIRM}
        {--force : Acknowledge rollback intent}';

    public function __construct(
        private readonly RefundMigrationRollbackService $rollbackService,
        private readonly PilotRefundMigrationManifestLoader $manifestLoader,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to rollback without --force acknowledgement.');

            return self::FAILURE;
        }

        $expectedConfirm = trim((string) config('central_wallet.pilot_refund_migration.rollback_confirm_token', ''));
        $providedConfirm = trim((string) $this->option('confirm'));
        if ($expectedConfirm === '' || ! hash_equals($expectedConfirm, $providedConfirm)) {
            $this->error('Invalid or missing --confirm token.');

            return self::FAILURE;
        }

        if (! (bool) config('central_wallet.refund_migration.execution_enabled', false)) {
            $this->error('CENTRAL_WALLET_REFUND_MIGRATION_EXECUTION_ENABLED is false.');

            return self::FAILURE;
        }

        $ownerApprovalRef = trim((string) $this->option('owner-approval-ref'));
        if ($ownerApprovalRef === '') {
            $this->error('--owner-approval-ref is required.');

            return self::FAILURE;
        }

        $refundId = $this->option('refund-id');
        if (! is_string($refundId) || $refundId === '') {
            $this->error('--refund-id is required.');

            return self::FAILURE;
        }

        $manifest = $this->option('manifest');
        $path = is_string($manifest) && $manifest !== '' ? $manifest : null;

        try {
            $manifestData = $this->manifestLoader->load($path);
            $allowed = $this->manifestLoader->normalizedAllowlist($manifestData);
            if (! in_array((int) $refundId, $allowed, true)) {
                throw new InvalidArgumentException('pilot_refund_id_not_allowlisted');
            }

            $migration = CentralWalletRefundMigration::query()
                ->where('refund_id', (int) $refundId)
                ->firstOrFail();

            $result = $this->rollbackService->rollback(
                $migration,
                $ownerApprovalRef,
                (string) Str::uuid(),
                'pilot_refund_migration_rollback',
            );

            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
