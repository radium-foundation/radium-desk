<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\RefundMigrationFalseReconcileRepairService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Description('Repair a falsely reconciled pilot refund migration (state only — no financial mutation)')]
class CentralWalletPilotRefundMigrationRepairFalseReconcileCommand extends Command
{
    protected $signature = 'central-wallet:pilot-refund-migration-repair-false-reconcile
        {--refund-id=387 : Pilot allowlisted refund id}
        {--confirm= : Required token matching CENTRAL_WALLET_PILOT_REFUND_MIGRATION_REPAIR_CONFIRM}';

    public function __construct(
        private readonly RefundMigrationFalseReconcileRepairService $repairService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $expected = trim((string) config('central_wallet.pilot_refund_migration.repair_confirm_token', ''));
        $provided = trim((string) $this->option('confirm'));

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            $this->error('Refusing repair without a valid --confirm token.');

            return self::FAILURE;
        }

        $refundId = (int) $this->option('refund-id');

        try {
            $result = $this->repairService->repair(
                $refundId,
                (string) Str::uuid(),
                'pilot_refund_migration_repair_command',
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
