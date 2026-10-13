<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\PilotRefundMigrationRecoverAbortedCutoverService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Description('Owner-gated recovery for a failed pilot refund migration after an aborted balance cutover (state only — no financial mutation)')]
class CentralWalletPilotRefundMigrationRecoverAbortedCutoverCommand extends Command
{
    protected $signature = 'central-wallet:pilot-refund-migration-recover-aborted-cutover
        {--refund-id= : Pilot allowlisted refund id}
        {--owner-recovery-ref= : Required owner recovery reference (must match configured pilot owner approval ref when set)}
        {--manifest= : Optional manifest JSON path}
        {--confirm= : Required token matching CENTRAL_WALLET_PILOT_REFUND_MIGRATION_RECOVER_ABORTED_CUTOVER_CONFIRM}';

    public function __construct(
        private readonly PilotRefundMigrationRecoverAbortedCutoverService $recoverService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $expected = trim((string) config('central_wallet.pilot_refund_migration.recover_aborted_cutover_confirm_token', ''));
        $provided = trim((string) $this->option('confirm'));

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            $this->error('Refusing recovery without a valid --confirm token.');

            return self::FAILURE;
        }

        $refundId = $this->option('refund-id');
        if (! is_numeric($refundId) || (int) $refundId <= 0) {
            $this->error('--refund-id is required.');

            return self::FAILURE;
        }

        $ownerRecoveryRef = trim((string) $this->option('owner-recovery-ref'));
        $manifest = $this->option('manifest');
        $manifestPath = is_string($manifest) && $manifest !== '' ? $manifest : null;

        try {
            $result = $this->recoverService->recover(
                (int) $refundId,
                $ownerRecoveryRef,
                (string) Str::uuid(),
                'pilot_refund_migration_recover_aborted_cutover_command',
                $manifestPath,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
