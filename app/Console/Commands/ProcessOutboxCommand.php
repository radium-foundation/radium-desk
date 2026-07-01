<?php

namespace App\Console\Commands;

use App\Services\Outbox\OutboxProcessorService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('outbox:process {--limit= : Maximum number of events to process}')]
#[Description('Process pending outbox events with locking, retries, and crash recovery')]
class ProcessOutboxCommand extends Command
{
    public function __construct(
        private readonly OutboxProcessorService $outboxProcessorService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limitOption = $this->option('limit');
        $limit = is_string($limitOption) && $limitOption !== ''
            ? max(1, (int) $limitOption)
            : null;

        $processed = $this->outboxProcessorService->process($limit);

        Log::info('Outbox processing completed.', [
            'processed' => $processed,
            'limit' => $limit,
        ]);

        $this->info(sprintf('Processed %d outbox event(s).', $processed));

        return self::SUCCESS;
    }
}
