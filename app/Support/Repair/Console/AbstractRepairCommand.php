<?php

namespace App\Support\Repair\Console;

use App\Support\Repair\Contracts\RepairDefinition;
use App\Support\Repair\Core\RepairEngine;
use App\Support\Repair\Data\RepairBatchOptions;
use App\Support\Repair\Data\RepairBatchSummary;
use App\Support\Repair\Enums\RepairPhase;
use App\Support\Repair\Services\RepairProgressReporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Shared CLI lifecycle for production repair commands.
 *
 * Concrete commands must include the standard options in their Signature
 * (see RepairClosedAppointmentWorkflowCommand for the canonical list).
 */
abstract class AbstractRepairCommand extends Command
{
    abstract protected function repairDefinition(): RepairDefinition;

    /**
     * @return array<string, mixed>
     */
    protected function domainExtras(): array
    {
        return [];
    }

    public function handle(RepairEngine $engine): int
    {
        try {
            $options = $this->buildOptions();
            $definition = $this->repairDefinition();

            if ($options->phase === RepairPhase::Preview) {
                $this->info('Preview mode — no domain mutations will be written.');
            }

            if ($options->phase === RepairPhase::Execute
                && $options->execute
                && ! $options->force
                && ! $this->confirmExecution($definition)) {
                $this->info('Repair cancelled.');

                return self::SUCCESS;
            }

            if ($options->phase === RepairPhase::Rollback
                && $options->execute
                && ! $options->force
                && ! $this->confirm('Rollback batch '.$options->batchUuid.'?', false)) {
                $this->info('Rollback cancelled.');

                return self::SUCCESS;
            }

            $reporter = new RepairProgressReporter(
                output: $this->output,
                quiet: $options->quiet,
                json: $options->json,
            );

            $summary = $engine->run($definition, $options, $reporter);

            return $this->exitCode($summary);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    protected function buildOptions(): RepairBatchOptions
    {
        $rollback = (bool) $this->option('rollback');
        $verifyOnly = (bool) $this->option('verify-only');
        $executeFlag = (bool) $this->option('execute');
        $dryRunFlag = (bool) $this->option('dry-run');

        if ($rollback) {
            $phase = RepairPhase::Rollback;
            // Rollback mutates only with --execute; otherwise preview rollback.
            $execute = $executeFlag && ! $dryRunFlag;
            $dryRun = ! $execute;
        } elseif ($verifyOnly) {
            $phase = RepairPhase::Verify;
            $execute = false;
            $dryRun = true;
        } elseif ($executeFlag && ! $dryRunFlag) {
            $phase = RepairPhase::Execute;
            $execute = true;
            $dryRun = false;
        } else {
            $phase = RepairPhase::Preview;
            $execute = false;
            $dryRun = true;
        }

        $limit = $this->option('limit');
        $checkpoint = $this->option('checkpoint');

        return new RepairBatchOptions(
            phase: $phase,
            dryRun: $dryRun,
            execute: $execute,
            force: (bool) $this->option('force'),
            quiet: (bool) $this->option('summary-only'),
            json: (bool) $this->option('json'),
            csv: (bool) $this->option('csv'),
            resume: (bool) $this->option('resume'),
            batchUuid: filled($this->option('batch')) ? (string) $this->option('batch') : null,
            limit: filled($limit) ? max(1, (int) $limit) : null,
            offset: max(0, (int) ($this->option('offset') ?? 0)),
            since: filled($this->option('since')) ? (string) $this->option('since') : null,
            until: filled($this->option('until')) ? (string) $this->option('until') : null,
            exportPath: filled($this->option('export')) ? (string) $this->option('export') : null,
            checkpointEvery: filled($checkpoint)
                ? max(1, (int) $checkpoint)
                : (int) config('repair.checkpoint_every', 10),
            notify: (bool) $this->option('notify-assignees'),
            extras: $this->domainExtras(),
        );
    }

    protected function confirmExecution(RepairDefinition $definition): bool
    {
        $this->warn($definition->title());
        $this->line('Phase: EXECUTE — this will mutate production data.');

        if (! $this->confirm('Continue with execute?', false)) {
            return false;
        }

        if ($definition->requiresDoubleConfirm() && app()->environment('production')) {
            $typed = (string) $this->ask('Type the repair key to confirm');

            return $typed === $definition->key();
        }

        return true;
    }

    protected function exitCode(RepairBatchSummary $summary): int
    {
        return ($summary->counts['failed'] ?? 0) > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
