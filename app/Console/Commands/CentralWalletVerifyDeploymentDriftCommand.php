<?php

namespace App\Console\Commands;

use App\CentralWallet\Reliability\CentralWalletDeploymentDriftVerifier;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

final class CentralWalletVerifyDeploymentDriftCommand extends Command
{
    protected $signature = 'central-wallet:verify-deployment-drift {--json : Emit machine-readable JSON}';

    protected $description = 'Verify Central Wallet runtime deployment drift (read-only)';

    public function handle(CentralWalletDeploymentDriftVerifier $verifier): int
    {
        $report = $verifier->verifyProvider();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ($report['status'] ?? 'FAIL') === 'PASS'
                ? SymfonyCommand::SUCCESS
                : SymfonyCommand::FAILURE;
        }

        $this->info('Central Wallet deployment drift: '.($report['status'] ?? 'FAIL'));

        foreach ($report['checks'] ?? [] as $check) {
            $this->line(sprintf(
                '- [%s] %s',
                $check['result'] ?? 'UNKNOWN',
                $check['id'] ?? 'unknown_check',
            ));
        }

        return ($report['status'] ?? 'FAIL') === 'PASS'
            ? SymfonyCommand::SUCCESS
            : SymfonyCommand::FAILURE;
    }
}
