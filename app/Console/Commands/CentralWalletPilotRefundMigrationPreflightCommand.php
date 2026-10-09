<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\PilotRefundMigrationOrchestrator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;

#[Description('Read-only preflight for a single-row pilot Central Wallet refund migration manifest')]
class CentralWalletPilotRefundMigrationPreflightCommand extends Command
{
    protected $signature = 'central-wallet:pilot-refund-migration-preflight
        {--manifest= : Optional manifest JSON path}';

    public function __construct(
        private readonly PilotRefundMigrationOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $manifest = $this->option('manifest');
        $path = is_string($manifest) && $manifest !== '' ? $manifest : null;

        $result = $this->orchestrator->preflight($path);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $result['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
