<?php

namespace App\Console\Commands;

use App\Services\Operations\IraCommunicationService;
use Illuminate\Console\Command;

class SendProductionWatchdogAlertsCommand extends Command
{
    protected $signature = 'watchdog:send-critical-alerts';

    protected $description = 'Send critical production watchdog alerts to superadmin via Telegram';

    public function handle(IraCommunicationService $communicationService): int
    {
        if (! (bool) config('ira.watchdog.enabled', true)) {
            $this->info('Production watchdog is disabled.');

            return self::SUCCESS;
        }

        $results = $communicationService->sendCriticalAlerts();
        $sentCount = count(array_filter(
            $results,
            fn ($notification) => $notification->status->value === 'sent',
        ));

        $this->info("Production watchdog processed. {$sentCount} critical alert(s) delivered.");

        return self::SUCCESS;
    }
}
