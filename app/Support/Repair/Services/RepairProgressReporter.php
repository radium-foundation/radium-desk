<?php

namespace App\Support\Repair\Services;

use App\Support\Repair\Data\RepairBatchSummary;
use App\Support\Repair\Data\RepairProgress;
use Illuminate\Console\OutputStyle;

class RepairProgressReporter
{
    public function __construct(
        private readonly ?OutputStyle $output = null,
        private readonly bool $quiet = false,
        private readonly bool $json = false,
    ) {}

    public function withOutput(?OutputStyle $output, bool $quiet = false, bool $json = false): self
    {
        return new self($output, $quiet, $json);
    }

    public function batchStarted(string $repairKey, string $phase, string $batchUuid, int $total): void
    {
        if ($this->quiet || $this->output === null) {
            return;
        }

        $this->output->writeln(sprintf(
            'Repair: %s | Phase: %s | Batch: %s | Candidates: %d',
            $repairKey,
            strtoupper($phase),
            $batchUuid,
            $total,
        ));
    }

    public function item(RepairProgress $progress): void
    {
        if ($this->quiet || $this->output === null) {
            return;
        }

        $this->output->writeln(sprintf(
            '[%3d/%d] %s  %s  %s',
            $progress->processed,
            $progress->total,
            $progress->currentSubjectKey,
            strtoupper($progress->currentAction),
            $progress->currentOutcome,
        ));
    }

    public function summary(RepairBatchSummary $summary): void
    {
        if ($this->json) {
            $this->output?->writeln(json_encode($summary->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return;
        }

        if ($this->output === null) {
            return;
        }

        $this->output->newLine();
        $this->output->writeln('Repair summary');
        $this->output->writeln('--------------');
        $this->output->writeln('Batch: '.$summary->batchUuid);
        $this->output->writeln('Status: '.$summary->status->value);
        $this->output->writeln('Phase: '.$summary->phase->value);

        foreach ($summary->counts as $key => $value) {
            $this->output->writeln(sprintf('%s: %s', $key, $value));
        }

        $this->output->writeln(sprintf('elapsed_seconds: %.2f', $summary->elapsedSeconds));

        if ($summary->reportPaths !== []) {
            $this->output->newLine();
            $this->output->writeln('Reports:');
            foreach ($summary->reportPaths as $type => $path) {
                if ($path !== null && $path !== '') {
                    $this->output->writeln(sprintf('- %s: %s', $type, $path));
                }
            }
        }

        if ($summary->failures !== [] && ! $this->quiet) {
            $this->output->newLine();
            $this->output->writeln('Failures:');
            foreach ($summary->failures as $failure) {
                $this->output->writeln(sprintf(
                    '- %s: %s',
                    $failure['subject_key'] ?? '?',
                    $failure['error'] ?? 'unknown',
                ));
            }
        }

        if ($summary->samples !== [] && ! $this->quiet) {
            $this->output->newLine();
            $this->output->writeln('Samples:');
            foreach (array_slice($summary->samples, 0, 20) as $sample) {
                $this->output->writeln(json_encode($sample, JSON_UNESCAPED_SLASHES) ?: '{}');
            }
        }
    }
}
