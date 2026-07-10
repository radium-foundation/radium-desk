<?php

namespace App\Console\Commands;

use App\Services\Operations\IraCommunicationService;
use App\Services\Operations\IraOwnerIntelligenceService;
use Illuminate\Console\Command;

class SendIraOwnerIntelligenceCommand extends Command
{
    protected $signature = 'ira:send-owner-intelligence {--period=morning : Report period (morning or evening)}';

    protected $description = 'Send Ira owner intelligence reports via Telegram to superadmin only';

    public function handle(
        IraOwnerIntelligenceService $intelligenceService,
        IraCommunicationService $communicationService,
    ): int {
        $period = $this->resolvePeriod((string) $this->option('period'));

        $report = $period === 'evening'
            ? $intelligenceService->buildEveningReport()
            : $intelligenceService->buildMorningReport();

        $sentCount = 0;

        foreach ($communicationService->ownerIntelligenceRecipients() as $user) {
            $results = $communicationService->sendOwnerIntelligenceReport($user, $report);
            $sentCount += count(array_filter(
                $results,
                fn ($notification) => $notification->status->value === 'sent',
            ));
        }

        $this->info("Ira owner intelligence ({$period}) processed. {$sentCount} message(s) delivered.");

        return self::SUCCESS;
    }

    private function resolvePeriod(string $period): string
    {
        return in_array($period, ['morning', 'evening'], true) ? $period : 'morning';
    }
}
