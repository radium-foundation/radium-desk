<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\DuplicateIdentityRemediationService;
use Illuminate\Console\Command;

final class CentralWalletRemediateDuplicateIdentityCommand extends Command
{
    protected $signature = 'central-wallet:remediate-duplicate-identity
                            {--dry-run : Validate preconditions without mutating data}
                            {--execute : Apply identity remediation (required for mutation)}';

    protected $description = 'One-time identity remediation: retire verified duplicate customer/CWID and unify site accounts';

    public function handle(DuplicateIdentityRemediationService $remediation): int
    {
        if (! $this->option('execute') && ! $this->option('dry-run')) {
            $this->error('Specify --dry-run or --execute');

            return self::FAILURE;
        }

        $dryRun = ! $this->option('execute');

        try {
            $report = $remediation->remediateOwnerUser3Duplicate($dryRun);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
