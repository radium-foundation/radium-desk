<?php

namespace App\Console\Commands;

use App\Services\Operations\IraCommunicationService;
use Illuminate\Console\Command;

class SendIraReadyQueueDigestCommand extends Command
{
    protected $signature = 'ira:send-ready-queue-digest';

    protected $description = 'Send Ready Queue summary via Telegram to operational Admin recipients';

    public function handle(IraCommunicationService $communicationService): int
    {
        $results = $communicationService->sendReadyQueueDigest();
        $sentCount = count(array_filter(
            $results,
            fn ($notification) => $notification->status->value === 'sent',
        ));

        $this->info("Ready Queue digest processed. {$sentCount} message(s) delivered.");

        return self::SUCCESS;
    }
}
