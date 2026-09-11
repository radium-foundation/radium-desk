<?php

namespace App\Infrastructure\Queue;

use App\Enums\QueueWorkerMode;

final class QueueDeadLetterCopy
{
    /**
     * Runtime that actually drains jobs. QUEUE_WORKER_MODE=dedicated_cron is a
     * leftover Hostinger label; KVM8 production uses Supervisor queue:work.
     */
    public static function processorLabel(?QueueWorkerMode $mode = null): string
    {
        $configured = (string) config('infrastructure.queue_runtime_label', '');

        if ($configured !== '') {
            return $configured;
        }

        $mode ??= QueueWorkerMode::fromConfig();

        return match ($mode) {
            QueueWorkerMode::Horizon => 'Horizon',
            QueueWorkerMode::Scheduler => 'scheduler queue:work',
            QueueWorkerMode::Disabled => 'disabled',
            QueueWorkerMode::Supervisor, QueueWorkerMode::DedicatedCron => 'Supervisor queue:work',
        };
    }

    public static function healthy(?QueueWorkerMode $mode = null): string
    {
        return 'Desk queue worker ('.self::processorLabel($mode).') is healthy.';
    }

    /**
     * @deprecated Use actionableDetail(). Kept so older tests/callers that pass the
     *             config mode string still compile during rollout.
     */
    public static function detail(string $workerMode, int $failedJobs): string
    {
        return self::actionableDetail($failedJobs, 0);
    }

    public static function actionableDetail(int $actionable, int $historical): string
    {
        $processor = self::processorLabel();

        if ($actionable === 0 && $historical > 0) {
            return "Desk queue ({$processor}): 0 current failed jobs. {$historical} historical retired-infrastructure row(s) remain stored (not deleted).";
        }

        $message = "Desk queue ({$processor}): {$actionable} current failed job(s) in the dead-letter queue.";

        if ($historical > 0) {
            $message .= " {$historical} historical retired-infrastructure row(s) remain stored.";
        }

        return $message;
    }
}
