<?php

namespace App\Console\Commands;

use App\Services\Operations\IraAssignmentTelegramBatchService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('ira:flush-assignment-telegram-batches')]
#[Description('Flush due IRA assignment Telegram batches')]
class FlushIraAssignmentTelegramBatchesCommand extends Command
{
    public function __construct(
        private readonly IraAssignmentTelegramBatchService $batchService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $flushed = $this->batchService->flushDue();

        Log::info('IRA assignment Telegram batch flush completed.', [
            'flushed' => $flushed,
        ]);

        $this->info(sprintf('Flushed %d IRA assignment Telegram batch(es).', $flushed));

        return self::SUCCESS;
    }
}
