<?php

namespace App\Console\Commands;

use App\Support\SchedulerTimingLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Single every-minute dispatcher for light scheduler work.
 *
 * Runs the former per-command every-minute jobs in-process so Hostinger pays
 * one artisan bootstrap instead of four. Each step still invokes the original
 * Artisan command (same handle() / services) — no business-logic fork.
 */
#[Signature('schedule:light-tick')]
#[Description('Run light every-minute scheduler steps in one process')]
class ScheduleLightTickCommand extends Command
{
    /**
     * @var list<array{command: string, parameters: array<string, mixed>, log: string, when: bool}>
     */
    private array $steps = [];

    public function handle(): int
    {
        $this->steps = $this->buildSteps();

        $failed = 0;
        $ran = 0;
        $skipped = 0;

        foreach ($this->steps as $step) {
            if (! $step['when']) {
                $skipped++;
                $this->line(sprintf('Skipped %s (disabled).', $step['command']));

                continue;
            }

            $ran++;
            $exit = $this->runStep($step['command'], $step['parameters'], $step['log']);
            if ($exit !== self::SUCCESS) {
                $failed++;
            }
        }

        $this->info(sprintf(
            'Light tick finished: ran %d, skipped %d, failed %d.',
            $ran,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<array{command: string, parameters: array<string, mixed>, log: string, when: bool}>
     */
    private function buildSteps(): array
    {
        $outboxLimit = max(1, (int) config('scheduler.outbox_process_limit', 50));
        $automationPendingLimit = max(1, (int) config('scheduler.automation_pending_limit', 25));

        return [
            [
                'command' => 'service-cases:process-automation-pending',
                'parameters' => ['--limit' => $automationPendingLimit],
                'log' => 'automation-pending-assignments.log',
                'when' => (bool) config('service_case_assignment.automation_grace_period_enabled', true),
            ],
            [
                'command' => 'ira:flush-assignment-telegram-batches',
                'parameters' => [],
                'log' => 'ira-assignment-telegram-batches.log',
                'when' => (bool) config('ira.communication.assignment_telegram_batch.enabled', true),
            ],
            [
                'command' => 'outbox:process',
                'parameters' => ['--limit' => $outboxLimit],
                'log' => 'outbox-processor.log',
                'when' => true,
            ],
            [
                'command' => 'presence:process-timeouts',
                'parameters' => [],
                'log' => 'presence-timeouts.log',
                'when' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function runStep(string $command, array $parameters, string $logFile): int
    {
        $started = hrtime(true);
        $exit = self::FAILURE;
        $output = '';

        try {
            try {
                $exit = Artisan::call($command, $parameters);
                $output = Artisan::output();
            } catch (Throwable $e) {
                $output = $e->getMessage().PHP_EOL;
                $exit = self::FAILURE;
                report($e);
            }

            if ($output !== '') {
                $this->output->write($output);
                $this->appendLog($logFile, $output);
            }

            return $exit;
        } finally {
            $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);
            app(SchedulerTimingLogger::class)->write([
                'event' => 'light_tick_step',
                'command' => $command,
                'duration_ms' => $durationMs,
                'exit' => $exit,
                'parent' => 'schedule:light-tick',
            ]);
        }
    }

    private function appendLog(string $logFile, string $output): void
    {
        $path = storage_path('logs/'.$logFile);
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $output, FILE_APPEND | LOCK_EX);
    }
}
