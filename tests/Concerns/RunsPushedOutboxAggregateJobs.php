<?php

namespace Tests\Concerns;

use App\Jobs\Outbox\ProcessOutboxAggregateJob;
use Illuminate\Support\Facades\Queue;

trait RunsPushedOutboxAggregateJobs
{
    protected function runPushedOutboxAggregateJobs(): void
    {
        $pushed = Queue::pushed(ProcessOutboxAggregateJob::class);

        foreach ($pushed as $job) {
            if (! $job instanceof ProcessOutboxAggregateJob) {
                continue;
            }

            app()->call([$job, 'handle']);
        }
    }
}
