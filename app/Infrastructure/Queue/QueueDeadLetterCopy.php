<?php

namespace App\Infrastructure\Queue;

final class QueueDeadLetterCopy
{
    public static function detail(string $workerMode, int $failedJobs): string
    {
        return "Queue worker ({$workerMode}): {$failedJobs} failed job(s) in the dead-letter queue.";
    }
}
