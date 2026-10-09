<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\PilotRefundMigrationReprepareService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Description('Owner-gated recovery re-prepare for a pilot refund migration (state only — no financial mutation)')]
class CentralWalletPilotRefundMigrationReprepareCommand extends Command
{
    protected $signature = 'central-wallet:pilot-refund-migration-reprepare
        {--refund-id=387 : Pilot allowlisted refund id}
        {--owner-recovery-ref= : Required owner recovery reference (must match configured pilot owner approval ref when set)}
        {--confirm= : Required token matching CENTRAL_WALLET_PILOT_REFUND_MIGRATION_REPREPARE_CONFIRM}';

    public function __construct(
        private readonly PilotRefundMigrationReprepareService $reprepareService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $expected = trim((string) config('central_wallet.pilot_refund_migration.reprepare_confirm_token', ''));
        $provided = trim((string) $this->option('confirm'));

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            $this->error('Refusing re-prepare without a valid --confirm token.');

            return self::FAILURE;
        }

        $refundId = (int) $this->option('refund-id');
        $ownerRecoveryRef = trim((string) $this->option('owner-recovery-ref'));

        try {
            $result = $this->reprepareService->reprepare(
                $refundId,
                $ownerRecoveryRef,
                (string) Str::uuid(),
                'pilot_refund_migration_reprepare_command',
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
