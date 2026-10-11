<?php

namespace App\Console\Commands;

use App\Enums\LegacySafeCashfreePaymentLinkResult;
use App\Services\Cashfree\LegacySafeCashfreePaymentLinkService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

#[Description('Gated link: P-04-10-196 SAFE_TO_LINK legacy orders via Cashfree webhook processor (P-04-10-194 path)')]
class LinkLegacySafeCashfreePaymentsCommand extends Command
{
    protected $signature = 'cashfree:link-legacy-safe-payments
        {--report=storage/app/private/legacy-cf-discovery-P-04-10-196.json : Approved discovery JSON report}
        {--dry-run : Revalidate only; default when --execute is omitted}
        {--execute : Perform production payment linking (financial mutation)}
        {--order=* : Optional subset of business order id(s) for isolated runs}
        {--write= : Optional path to write JSON result report}';

    public function __construct(
        private readonly LegacySafeCashfreePaymentLinkService $linkService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute;

        /** @var list<string> $orders */
        $orders = array_values(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            (array) $this->option('order'),
        )));

        $reportPath = (string) $this->option('report');

        try {
            $eligible = $this->linkService->loadEligibleSafeRows($reportPath);
        } catch (InvalidArgumentException $exception) {
            $this->error('Pre-mutation gate failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Production gate — RadiumDesk-P-04-10-197');
        $this->line('Eligible: '.count($eligible).' SAFE_TO_LINK records (from discovery report).');
        $this->line('Excluded: 118 AMBIGUOUS + 15 NO_CASHFREE_PAYMENT (not in allowlist).');
        $this->line('Mutations allowed: existing-order Cashfree payment linking ONLY.');
        $this->line('Mutations forbidden: refunds, manual wallet, RadiumBox edits, schema, missed-webhook heal.');
        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY-RUN mode (pass --execute to mutate).');
        } else {
            $this->warn('EXECUTE mode — financial mutation enabled.');
        }

        if ($orders !== []) {
            $this->line('Subset filter: '.implode(', ', $orders));
        }

        try {
            $report = $this->linkService->linkFromDiscoveryReport(
                reportPath: $reportPath,
                dryRun: $dryRun,
                onlyBusinessOrderIds: $orders !== [] ? $orders : null,
            );
        } catch (RuntimeException $exception) {
            $this->error('Batch stopped: '.$exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Link operation failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $totals = $report->totalsByResult();
        $this->newLine();
        $this->line('Processed: '.$report->eligibleCount.' eligible in this run.');

        foreach (LegacySafeCashfreePaymentLinkResult::cases() as $case) {
            $count = $totals[$case->value] ?? 0;

            if ($count > 0) {
                $this->line(sprintf('  %s: %d', $case->value, $count));
            }
        }

        $writePath = trim((string) $this->option('write'));

        if ($writePath !== '') {
            $absolute = $writePath[0] === '/' ? $writePath : base_path($writePath);
            File::ensureDirectoryExists(dirname($absolute));
            File::put($absolute, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->info('Wrote result report: '.$absolute);
        }

        foreach ($report->rows as $row) {
            if ($row->result === LegacySafeCashfreePaymentLinkResult::FailedMutation
                || $row->result === LegacySafeCashfreePaymentLinkResult::FailedPostValidation) {
                $this->error($row->deskOrderNumber.' — '.$row->result->value.': '.$row->reason);
            }
        }

        $failed = ($totals[LegacySafeCashfreePaymentLinkResult::FailedMutation->value] ?? 0)
            + ($totals[LegacySafeCashfreePaymentLinkResult::FailedPostValidation->value] ?? 0);

        if ($failed > 0 && $execute) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
