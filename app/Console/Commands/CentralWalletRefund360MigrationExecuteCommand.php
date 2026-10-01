<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\Refund360MigrationManifestLoader;
use App\CentralWallet\Application\Refund360MigrationOrchestrator;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('central-wallet:refund360-migration-execute
    {--owner-approval-ref= : Required owner approval reference}
    {--refund-id=360 : Refund migration row ID (360 only)}
    {--manifest= : Optional manifest JSON path}
    {--force : Acknowledge execution intent (still requires execution flag)}')]
#[Description('Execute refund 360 radiumbox.com Central Wallet migration (gated — default OFF)')]
class CentralWalletRefund360MigrationExecuteCommand extends Command
{
    public function __construct(
        private readonly Refund360MigrationOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to execute without --force acknowledgement.');

            return self::FAILURE;
        }

        $ownerApprovalRef = trim((string) $this->option('owner-approval-ref'));
        if ($ownerApprovalRef === '') {
            $this->error('--owner-approval-ref is required.');

            return self::FAILURE;
        }

        if ($ownerApprovalRef !== Refund360MigrationManifestLoader::EXPECTED_OWNER_APPROVAL_REF) {
            $this->error('owner_approval_ref_mismatch');

            return self::FAILURE;
        }

        $refundId = $this->option('refund-id');
        if (! is_string($refundId) || $refundId === '') {
            $this->error('--refund-id is required (360 only).');

            return self::FAILURE;
        }

        $refundIdInt = (int) $refundId;
        if (! in_array($refundIdInt, Refund360MigrationOrchestrator::EXECUTABLE_REFUND_IDS, true)) {
            $this->error('Refund ID '.$refundIdInt.' is not in the Refund360 executable allowlist.');

            return self::FAILURE;
        }

        $manifest = $this->option('manifest');
        $path = is_string($manifest) && $manifest !== '' ? $manifest : null;

        try {
            $migration = CentralWalletRefundMigration::query()
                ->where('refund_id', $refundIdInt)
                ->where('batch_id', Refund360MigrationManifestLoader::BATCH_ID)
                ->firstOrFail();

            $result = $this->orchestrator->executeSingle(
                $migration,
                $ownerApprovalRef,
                null,
                'refund360_refund_migration_execute_command',
                $path,
            );
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
