<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\HistoricalWalletRefundReconciliationCohortAnalyzer;
use App\CentralWallet\Application\WalletRefundAccountingDiscrepancyClassifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;

#[Description('Read-only report: wallet refunds vs Desk Central Wallet ledger authority (dry-run inventory)')]
class CentralWalletWalletRefundDiscrepancyReportCommand extends Command
{
    protected $signature = 'central-wallet:wallet-refund-discrepancy-report
        {--limit= : Max terminal wallet refunds to scan (default: all)}
        {--after-refund-id= : Scan refunds with id greater than this value}
        {--violations-only : Include only policy_violation rows in output}
        {--reconciliation-cohort : Read-only eligible vs blocked paired-reconciliation cohort (dry-run)}';

    public function __construct(
        private readonly WalletRefundAccountingDiscrepancyClassifier $classifier,
        private readonly HistoricalWalletRefundReconciliationCohortAnalyzer $cohortAnalyzer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = $this->option('limit');
        $after = $this->option('after-refund-id');
        $violationsOnly = (bool) $this->option('violations-only');

        $limitInt = is_numeric($limit) ? (int) $limit : null;
        $afterInt = is_numeric($after) ? (int) $after : null;

        if ((bool) $this->option('reconciliation-cohort')) {
            $report = $this->cohortAnalyzer->analyze($limitInt, $afterInt);
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $report = $this->classifier->buildReport($limitInt, $afterInt);

        if ($violationsOnly) {
            $report['rows'] = array_values(array_filter(
                $report['rows'],
                static fn (array $row): bool => ($row['policy_violation'] ?? false) === true,
            ));
            $report['violations_only'] = true;
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
