<?php

namespace App\Console\Commands;

use App\CentralWallet\Reliability\CentralWalletReleaseGateReporter;
use App\CentralWallet\Reliability\CentralWalletReleaseGateRunner;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

final class CentralWalletVerifyReleaseGateCommand extends Command
{
    protected $signature = 'central-wallet:verify-release-gate
                            {--phase=pre : pre or post deployment}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Run Central Wallet production release gate (read-only, does not authorize deployment)';

    public function handle(CentralWalletReleaseGateRunner $runner, CentralWalletReleaseGateReporter $reporter): int
    {
        $phase = strtolower(trim((string) $this->option('phase')));
        if (! in_array($phase, ['pre', 'post'], true)) {
            $this->error('Invalid --phase; use pre or post.');

            return SymfonyCommand::FAILURE;
        }

        $report = $runner->run($phase);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line($reporter->toHuman($report));
        }

        return match ($report['final'] ?? 'FAIL') {
            'PASS' => SymfonyCommand::SUCCESS,
            'BLOCKED' => SymfonyCommand::FAILURE,
            default => SymfonyCommand::FAILURE,
        };
    }
}
