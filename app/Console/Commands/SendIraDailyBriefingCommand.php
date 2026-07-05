<?php

namespace App\Console\Commands;

use App\Services\Operations\IraCommunicationService;
use App\Services\Operations\IraOperationsBrainService;
use Illuminate\Console\Command;

class SendIraDailyBriefingCommand extends Command
{
    protected $signature = 'ira:send-daily-briefing';

    protected $description = 'Send Ira morning operational briefing via Telegram to owners';

    public function handle(
        IraOperationsBrainService $brainService,
        IraCommunicationService $communicationService,
    ): int {
        $briefing = $brainService->briefing(useCache: false);
        $sentCount = 0;

        foreach ($communicationService->dailyBriefingRecipients() as $user) {
            $results = $communicationService->sendDailyBriefing($user, $briefing);
            $sentCount += count(array_filter(
                $results,
                fn ($notification) => $notification->status->value === 'sent',
            ));
        }

        $this->info("Ira daily briefing processed. {$sentCount} message(s) delivered.");

        return self::SUCCESS;
    }
}
