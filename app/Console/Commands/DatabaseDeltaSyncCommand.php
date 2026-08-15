<?php

namespace App\Console\Commands;

use App\Infrastructure\DatabaseSync\DatabaseSyncApplyService;
use App\Infrastructure\DatabaseSync\DatabaseSyncDryRunService;
use App\Infrastructure\DatabaseSync\SyncVerificationReport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('db:sync-delta
    {--dry-run : Read-only drift report from Hostinger (source) to VPS (target)}
    {--apply : Apply a delta generation to the VPS target}
    {--vps-is-dark : Confirm the VPS has no scheduler, queue, webhooks, or public traffic}
    {--generation-id= : Optional generation identifier for apply mode}
    {--tier= : Limit inspection to a single sync tier}
    {--table= : Limit inspection to a single table}
    {--json : Output the structured report as JSON}')]
#[Description('Logical Checkpoint Delta Sync (Hostinger source → VPS target)')]
class DatabaseDeltaSyncCommand extends Command
{
    public function __construct(
        private readonly DatabaseSyncDryRunService $dryRunService,
        private readonly DatabaseSyncApplyService $applyService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('apply')) {
            return $this->handleApply();
        }

        if (! $this->option('dry-run')) {
            $this->error('db:sync-delta is dry-run only in Phase 1. Re-run with --dry-run.');

            return self::FAILURE;
        }

        return $this->handleDryRun();
    }

    private function handleApply(): int
    {
        if (! $this->option('vps-is-dark')) {
            $this->error('Apply mode requires --vps-is-dark to confirm the VPS remains dark.');

            return self::FAILURE;
        }

        $tier = $this->normalizedTierOption();
        $table = $this->normalizedTableOption();
        $generationId = $this->normalizedGenerationIdOption();

        $this->info('Logical Checkpoint Delta Sync — APPLY');
        $this->line('Direction: Hostinger SOURCE → VPS TARGET');
        $this->newLine();

        try {
            $report = $this->applyService->run($table, $tier, $generationId);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Apply generation completed: '.($report['generation_id'] ?? 'unknown'));

        return self::SUCCESS;
    }

    private function handleDryRun(): int
    {
        $tier = $this->normalizedTierOption();
        $table = $this->normalizedTableOption();

        $this->info('Logical Checkpoint Delta Sync — DRY RUN ONLY');
        $this->line('Direction: Hostinger SOURCE → VPS TARGET');
        $this->newLine();

        try {
            $report = $this->dryRunService->run($table, $tier);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line($report->toJson());

            return $report->hasBlockers() ? self::FAILURE : self::SUCCESS;
        }

        $this->renderHumanReport($report);

        return $report->hasBlockers() ? self::FAILURE : self::SUCCESS;
    }

    private function normalizedTierOption(): ?int
    {
        $tier = $this->option('tier');

        if ($tier === null || $tier === '') {
            return null;
        }

        if (! is_numeric($tier)) {
            throw new \InvalidArgumentException('The --tier option must be an integer.');
        }

        return (int) $tier;
    }

    private function normalizedTableOption(): ?string
    {
        $table = $this->option('table');

        if (! is_string($table)) {
            return null;
        }

        $trimmed = trim($table);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizedGenerationIdOption(): ?string
    {
        $generationId = $this->option('generation-id');

        if (! is_string($generationId)) {
            return null;
        }

        $trimmed = trim($generationId);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param  SyncVerificationReport  $report
     */
    private function renderHumanReport($report): void
    {
        $this->info('Endpoints');
        $this->table(
            ['Role', 'Name', 'Host', 'Port', 'Path'],
            [
                [
                    'SOURCE',
                    $report->source['label'] ?? '',
                    $report->source['ssh_host'] ?? '',
                    $report->source['ssh_port'] ?? '',
                    $report->source['project_path'] ?? '',
                ],
                [
                    'TARGET',
                    $report->target['label'] ?? '',
                    $report->target['ssh_host'] ?? '',
                    $report->target['ssh_port'] ?? '',
                    $report->target['project_path'] ?? '',
                ],
            ],
        );

        $this->newLine();
        $this->info('Schema parity');
        $this->line($report->schemaParity->matched ? 'Matched' : 'Mismatch');
        foreach ($report->schemaParity->blockers as $blocker) {
            $this->warn($blocker);
        }

        $this->newLine();
        $this->info('Table drift');
        $rows = [];

        foreach ($report->tables as $table) {
            $rows[] = [
                $table['table'] ?? '',
                $table['tier'] ?? '',
                $table['cursor_strategy'] ?? '',
                $table['source']['count'] ?? 'n/a',
                $table['target']['count'] ?? 'n/a',
                $table['count_drift'] ?? 'n/a',
                $table['source']['max_primary_key'] ?? 'n/a',
                $table['target']['max_primary_key'] ?? 'n/a',
                $table['source']['max_updated_at'] ?? 'n/a',
                $table['target']['max_updated_at'] ?? 'n/a',
            ];
        }

        $this->table(
            ['Table', 'Tier', 'Strategy', 'Src Count', 'Tgt Count', 'Drift', 'Src Max PK', 'Tgt Max PK', 'Src Max updated_at', 'Tgt Max updated_at'],
            $rows,
        );

        if ($report->warnings !== []) {
            $this->newLine();
            $this->warn('Warnings');
            foreach ($report->warnings as $warning) {
                $this->line('- '.$warning);
            }
        }

        if ($report->blockers !== []) {
            $this->newLine();
            $this->error('Blockers');
            foreach ($report->blockers as $blocker) {
                $this->line('- '.$blocker);
            }
        }
    }
}
