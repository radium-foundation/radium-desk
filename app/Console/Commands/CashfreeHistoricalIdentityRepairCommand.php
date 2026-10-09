<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\CashfreeHistoricalIdentityRepairRunner;
use App\CentralWallet\Application\CashfreeHistoricalIdentityRepairRunSummary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;

#[Description('Historical Cashfree order identity repair (explicit dry-run or gated apply)')]
class CashfreeHistoricalIdentityRepairCommand extends Command
{
    protected $signature = 'cashfree:repair-historical-identity
        {--mode= : Required. dry-run or apply}
        {--limit= : Maximum email cohorts to process (never splits one email across runs)}
        {--order=* : Restrict to business order id(s), e.g. RD16854}
        {--email= : Restrict to one normalized email}
        {--run-id= : Optional run identifier for audit correlation}';

    public function __construct(
        private readonly CashfreeHistoricalIdentityRepairRunner $runner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $mode = strtolower(trim((string) $this->option('mode')));
        if ($mode === '') {
            $this->error('The --mode option is required. Use --mode=dry-run or --mode=apply.');

            return self::FAILURE;
        }

        if (! in_array($mode, ['dry-run', 'apply'], true)) {
            $this->error('Invalid --mode. Allowed values: dry-run, apply.');

            return self::FAILURE;
        }

        if ($mode === 'apply') {
            $this->error('Apply mode is implemented but disabled by default. Set CENTRAL_WALLET_HISTORICAL_IDENTITY_REPAIR_APPLY_ENABLED=true and pass --confirm on a future authorized run. This prompt does not execute apply.');

            return self::FAILURE;
        }

        $limit = $this->option('limit');
        $cohortLimit = ($limit !== null && $limit !== '') ? max(1, (int) $limit) : null;

        /** @var list<string> $orders */
        $orders = array_values(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            (array) $this->option('order'),
        )));

        $email = $this->filledOption('email');
        $runId = $this->filledOption('run-id');

        $summary = $this->runner->run(
            mode: 'dry-run',
            cohortLimit: $cohortLimit,
            businessOrderIds: $orders !== [] ? $orders : null,
            normalizedEmail: $email,
            runId: $runId,
        );

        $this->renderSummary($summary);

        return self::SUCCESS;
    }

    private function renderSummary(CashfreeHistoricalIdentityRepairRunSummary $summary): void
    {
        $m = $summary->metrics;

        $this->info('Cashfree historical identity repair — '.$summary->mode);
        $this->line('Run: '.$summary->runId);
        $this->line('Population cutoff (UTC): '.($m['deploy_cutoff_utc'] ?? ''));
        $this->line('Population orders (in this run): '.($m['population_orders'] ?? 0));
        $this->line('Unique normalized emails: '.($m['unique_normalized_emails'] ?? 0));
        $this->line('Email cohorts: '.($m['cohorts_planned'] ?? 0));

        $identity = $m['identity_class_counts'] ?? [];
        $this->newLine();
        $this->info('Identity classification (orders)');
        foreach ($identity as $key => $count) {
            $this->line("  {$key}: {$count}");
        }

        $this->newLine();
        $this->info('Eligible cohorts');
        $this->line('  Existing customer bind cohorts: '.($m['existing_customer_cohorts'] ?? 0));
        $this->line('  New customer cohorts: '.($m['new_customer_cohorts'] ?? 0));
        $this->line('  Orders to bind: '.($m['orders_to_bind'] ?? 0));
        $this->line('  Invalid/missing email orders: '.($identity['INVALID_OR_MISSING_EMAIL'] ?? 0));
        $this->line('  Ambiguous orders: '.($identity['AMBIGUOUS'] ?? 0));

        $this->newLine();
        $this->info('Refund exposure (cohorts — informational only)');
        foreach ($m['refund_exposure_cohorts'] ?? [] as $key => $count) {
            if ((int) $count > 0) {
                $this->line("  {$key}: {$count}");
            }
        }

        $this->newLine();
        $this->info('Dry-run mutations');
        $this->line('  Planned order customer_id updates: '.$summary->plannedMutations.' (informational — no writes performed)');

        if ($summary->rd16854 !== null) {
            $this->newLine();
            $this->info('RD16854');
            $this->line(json_encode($summary->rd16854, JSON_PRETTY_PRINT));
        }

        if ($summary->cohortSamples !== []) {
            $this->newLine();
            $this->info('Sample cohorts');
            $this->line(json_encode($summary->cohortSamples, JSON_PRETTY_PRINT));
        }
    }

    private function filledOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
