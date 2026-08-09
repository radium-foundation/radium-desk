<?php

namespace App\Listeners;

use App\Support\SchedulerTimingLogger;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Throwable;

/**
 * Records Laravel schedule task start/end/skip/fail/bg_finished into JSONL.
 */
class LogScheduledTaskTiming
{
    public function __construct(
        private readonly SchedulerTimingLogger $logger,
    ) {}

    public function handle(object $event): void
    {
        try {
            $task = $event->task ?? null;

            if (! $task instanceof Event) {
                return;
            }

            $payload = [
                'event' => $this->eventName($event),
                'command' => $this->commandIdentity($task),
                'run_in_background' => (bool) $task->runInBackground,
            ];

            if ($event instanceof ScheduledTaskFinished) {
                $payload['duration_ms'] = (int) round(((float) $event->runtime) * 1000);
                $payload['exit'] = $task->exitCode;
            } elseif ($event instanceof ScheduledTaskFailed) {
                $message = $event->exception->getMessage();
                if (strlen($message) > 200) {
                    $message = substr($message, 0, 200);
                }
                $payload['error'] = $event->exception::class.': '.$message;
                $payload['exit'] = $task->exitCode;
            } elseif ($event instanceof ScheduledBackgroundTaskFinished) {
                $payload['exit'] = $task->exitCode;
            }

            $this->logger->write($payload);
        } catch (Throwable) {
            // Never rethrow into the scheduler.
        }
    }

    private function eventName(object $event): string
    {
        return match (true) {
            $event instanceof ScheduledTaskStarting => 'starting',
            $event instanceof ScheduledTaskFinished => 'finished',
            $event instanceof ScheduledTaskSkipped => 'skipped',
            $event instanceof ScheduledTaskFailed => 'failed',
            $event instanceof ScheduledBackgroundTaskFinished => 'bg_finished',
            default => 'unknown',
        };
    }

    private function commandIdentity(Event $task): string
    {
        $raw = is_string($task->command) && $task->command !== ''
            ? $task->command
            : null;

        if ($raw === null) {
            if (is_string($task->description) && $task->description !== '') {
                return $task->description;
            }

            try {
                $mutex = $task->mutexName();
            } catch (Throwable) {
                $mutex = null;
            }

            return is_string($mutex) && $mutex !== '' ? $mutex : 'unknown';
        }

        if (preg_match("/artisan['\"]?\s+(.+)$/s", $raw, $matches) === 1) {
            return trim($matches[1]);
        }

        // Drop quoted php binary prefixes when "artisan" token is absent.
        $stripped = preg_replace("/^(['\"]).*?\\1\\s+/", '', $raw) ?? $raw;
        $stripped = trim((string) $stripped);

        if ($stripped !== '') {
            return $stripped;
        }

        if (is_string($task->description) && $task->description !== '') {
            return $task->description;
        }

        return 'unknown';
    }
}
