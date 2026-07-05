<?php

namespace App\Console\Commands;

use App\Services\Operations\IraCommunicationService;
use App\Services\Operations\IraOperationsBrainService;
use Illuminate\Console\Command;

class SendIraRiskAlertsCommand extends Command
{
    protected $signature = 'ira:send-risk-alerts';

    protected $description = 'Send high-priority Ira operational risk alerts via Telegram';

    public function handle(
        IraOperationsBrainService $brainService,
        IraCommunicationService $communicationService,
    ): int {
        $briefing = $brainService->briefing(useCache: false);
        $results = $communicationService->sendOperationalAlerts($briefing);
        $sentCount = count(array_filter(
            $results,
            fn ($notification) => $notification->status->value === 'sent',
        ));

        $this->info("Ira risk alerts processed. {$sentCount} message(s) delivered.");

        return self::SUCCESS;
    }
}
