<?php

namespace App\Console\Commands;

use App\CentralWallet\Reliability\CentralWalletOverlayIntegrityVerifier;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

final class CentralWalletVerifyOverlayIntegrityCommand extends Command
{
    protected $signature = 'central-wallet:verify-overlay-integrity {--json : Emit JSON report}';

    protected $description = 'Verify Central Wallet managed-file inventory, release identity, and orphan overlay detection';

    public function handle(CentralWalletOverlayIntegrityVerifier $verifier): int
    {
        $report = $verifier->verify();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ($report['status'] ?? 'FAIL') === 'PASS'
                ? SymfonyCommand::SUCCESS
                : SymfonyCommand::FAILURE;
        }

        $this->info('overlay_integrity_status='.($report['status'] ?? 'FAIL'));

        foreach ($report['checks'] ?? [] as $check) {
            $this->line(sprintf(
                '- %s: %s',
                $check['id'] ?? 'unknown',
                $check['result'] ?? 'UNKNOWN',
            ));
        }

        return ($report['status'] ?? 'FAIL') === 'PASS'
            ? SymfonyCommand::SUCCESS
            : SymfonyCommand::FAILURE;
    }
}
