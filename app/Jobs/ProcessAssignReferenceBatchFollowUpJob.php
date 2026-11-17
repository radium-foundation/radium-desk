<?php

namespace App\Jobs;

use App\Infrastructure\Queue\QueueRouting;
use App\Models\OutboxEvent;
use App\Services\AssignReference\AssignReferenceBatchFollowUpProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes deferred batch assign communications (admin DB notifications, driver guide WA/email).
 */
class ProcessAssignReferenceBatchFollowUpJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public int $timeout = 900;

    public function __construct(
        public readonly int $outboxEventId,
    ) {
        $this->onQueue(QueueRouting::notifications());
    }

    public function handle(AssignReferenceBatchFollowUpProcessor $processor): void
    {
        $event = OutboxEvent::query()->find($this->outboxEventId);

        if ($event === null) {
            Log::warning('bulk_assign.batch.follow_up.skipped', [
                'reason' => 'outbox_not_found',
                'outbox_event_id' => $this->outboxEventId,
            ]);

            return;
        }

        $processor->process($event);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('bulk_assign.batch.follow_up.job_failed', [
            'outbox_event_id' => $this->outboxEventId,
            'attempt' => $this->attempts(),
            'exception' => $exception?->getMessage(),
        ]);
    }
}
