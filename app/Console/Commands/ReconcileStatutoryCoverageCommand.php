<?php

namespace App\Console\Commands;

use App\Data\StatutoryCoverage\StatutoryCoverageWindow;
use App\Enums\StatutoryCoverage\StatutoryCoverageClassification;
use App\Services\StatutoryCoverage\StatutoryCoverageReconciliationService;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[AsCommand(
    name: 'desk:reconcile-statutory-coverage',
    description: 'Read-only cross-spoke statutory coverage reconciliation (dry-run; no financial writes)',
)]
class ReconcileStatutoryCoverageCommand extends Command
{
    protected $signature = 'desk:reconcile-statutory-coverage
                            {--channel=* : Limit to one or more channels (e.g. rdservice_in, radiumbox_com)}
                            {--from= : Window start (interpreted in --timezone)}
                            {--to= : Window end (defaults to now in --timezone)}
                            {--timezone= : IANA timezone (default config statutory_coverage.timezone)}
                            {--page=1 : Page for paginated desk/source retrieval}
                            {--per-page= : Orders per page (default config pagination.default_per_page)}
                            {--exceptions : Include per-order exception rows in output}
                            {--max-exceptions=200 : Cap exception rows per channel}
                            {--json : Emit structured JSON report}';

    public function handle(StatutoryCoverageReconciliationService $reconciliation): int
    {
        $timezone = (string) ($this->option('timezone') ?: config('statutory_coverage.timezone', 'Asia/Kolkata'));
        $fromRaw = $this->option('from');
        $toRaw = $this->option('to');

        $from = is_string($fromRaw) && trim($fromRaw) !== ''
            ? Carbon::parse(trim($fromRaw), $timezone)
            : Carbon::now($timezone)->startOfMonth();

        $to = is_string($toRaw) && trim($toRaw) !== ''
            ? Carbon::parse(trim($toRaw), $timezone)
            : Carbon::now($timezone);

        if ($to->lessThan($from)) {
            $this->error('--to must be on or after --from.');

            return self::FAILURE;
        }

        $perPage = $this->option('per-page');
        $perPage = is_numeric($perPage) ? (int) $perPage : (int) config('statutory_coverage.pagination.default_per_page', 500);
        $perPage = min($perPage, (int) config('statutory_coverage.pagination.max_per_page', 2000));

        $page = max(1, (int) $this->option('page'));
        $channels = $this->option('channel');
        $channelFilter = is_array($channels) ? array_values(array_filter($channels, fn ($c) => is_string($c) && $c !== '')) : null;

        $window = new StatutoryCoverageWindow(from: $from, to: $to, timezone: $timezone);

        $report = $reconciliation->reconcile(
            channelFilter: $channelFilter,
            window: $window,
            page: $page,
            perPage: $perPage,
            includeExceptions: (bool) $this->option('exceptions'),
            maxExceptions: max(1, (int) $this->option('max-exceptions')),
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Statutory coverage reconciliation (read-only dry-run)');
        $this->line('Generated: '.$report->generatedAt->toIso8601String());
        $this->line('Window: '.$from->toIso8601String().' → '.$to->toIso8601String().' ('.$timezone.')');
        $this->newLine();

        foreach ($report->channels as $channelReport) {
            $this->info($channelReport->channelLabel.' ['.$channelReport->channel.']');
            $this->line('  Source population: '.json_encode($channelReport->sourcePopulation->toArray()));
            $this->line('  Desk population (page): '.$channelReport->deskPopulationCount.' / '.$channelReport->deskPopulationTotal);
            $this->line('  Desk population complete: '.($channelReport->deskPopulationComplete ? 'yes' : 'no'));
            $this->line('  Source population complete: '.($channelReport->sourcePopulationComplete ? 'yes' : 'no'));
            $this->line('  Report complete: '.($channelReport->reportComplete ? 'yes' : 'no'));
            if ($channelReport->partialReason) {
                $this->line('  Partial reason: '.$channelReport->partialReason);
            }

            $rows = [];
            foreach ($channelReport->counts as $key => $count) {
                $enum = StatutoryCoverageClassification::tryFrom($key);
                $rows[] = [$enum?->label() ?? $key, $count];
            }
            $this->table(['Classification', 'Count'], $rows);

            if ($channelReport->exceptions !== []) {
                $this->line('  Exceptions (sample):');
                foreach ($channelReport->exceptions as $exception) {
                    $this->line('    '.$exception->sourceId.' → '.$exception->classification->value.' ('.$exception->reason.')');
                }
            }

            $this->newLine();
        }

        if ($report->watchdogSuggestions !== []) {
            $this->info('Suggested watchdog keys (not sent):');
            foreach ($report->watchdogSuggestions as $key) {
                $this->line('  - '.$key);
            }
        }

        return self::SUCCESS;
    }
}
