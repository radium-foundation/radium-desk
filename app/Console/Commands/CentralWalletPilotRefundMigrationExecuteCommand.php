<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\PilotRefundMigrationOrchestrator;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Description('Execute a single allowlisted pilot Central Wallet refund migration (gated — default OFF)')]
class CentralWalletPilotRefundMigrationExecuteCommand extends Command
{
    protected $signature = 'central-wallet:pilot-refund-migration-execute
        {--mode=preflight : preflight or execute}
        {--owner-approval-ref= : Required owner approval reference for execute mode}
        {--refund-id= : Required refund id from manifest allowlist}
        {--manifest= : Optional manifest JSON path}
        {--confirm= : Required for execute mode; must match CENTRAL_WALLET_PILOT_REFUND_MIGRATION_EXECUTE_CONFIRM}
        {--force : Acknowledge execution intent (still requires execution flags)}';

    public function __construct(
        private readonly PilotRefundMigrationOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $mode = strtolower(trim((string) $this->option('mode')));
        $manifest = $this->option('manifest');
        $path = is_string($manifest) && $manifest !== '' ? $manifest : null;

        if ($mode === 'preflight' || $mode === '') {
            $result = $this->orchestrator->preflight($path);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return ($result['ready'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        if ($mode !== 'execute') {
            $this->error('Invalid --mode. Use preflight or execute.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->error('Refusing to execute without --force acknowledgement.');

            return self::FAILURE;
        }

        $expectedConfirm = trim((string) config('central_wallet.pilot_refund_migration.execute_confirm_token', ''));
        $providedConfirm = trim((string) $this->option('confirm'));
        if ($expectedConfirm === '' || ! hash_equals($expectedConfirm, $providedConfirm)) {
            $this->error('Invalid or missing --confirm token.');

            return self::FAILURE;
        }

        $ownerApprovalRef = trim((string) $this->option('owner-approval-ref'));
        if ($ownerApprovalRef === '') {
            $this->error('--owner-approval-ref is required for execute mode.');

            return self::FAILURE;
        }

        $refundId = $this->option('refund-id');
        if (! is_string($refundId) || $refundId === '') {
            $this->error('--refund-id is required for execute mode.');

            return self::FAILURE;
        }

        try {
            $migration = CentralWalletRefundMigration::query()
                ->where('refund_id', (int) $refundId)
                ->firstOrFail();

            $result = $this->orchestrator->executeSingle($migration, $ownerApprovalRef, $path);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
