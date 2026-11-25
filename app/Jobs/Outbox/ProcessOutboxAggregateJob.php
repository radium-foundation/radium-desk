<?php

namespace App\Jobs\Outbox;

use App\Infrastructure\Queue\QueueRouting;
use App\Services\Outbox\OutboxProcessorService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessOutboxAggregateJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 1800];

    public int $uniqueFor = 7200;

    public function __construct(
        public readonly string $aggregateType,
        public readonly int $aggregateId,
    ) {
        $this->onQueue(QueueRouting::critical());
    }

    public function uniqueId(): string
    {
        return $this->aggregateType.':'.$this->aggregateId;
    }

    public function handle(OutboxProcessorService $outboxProcessorService): void
    {
        $outboxProcessorService->processAggregate($this->aggregateType, $this->aggregateId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Outbox aggregate processing exhausted retries.', [
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'attempt' => $this->attempts(),
            'message' => $exception?->getMessage(),
        ]);
    }
}
