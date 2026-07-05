<?php

namespace App\Console\Commands;

use App\Services\Operations\IraCommunicationService;
use App\Services\Operations\TeamTelegramQuietRulesService;
use App\Services\Operations\TeamWorkBriefingService;
use Illuminate\Console\Command;

class SendTeamDailyBriefingCommand extends Command
{
    protected $signature = 'team-telegram:send-daily-briefings';

    protected $description = 'Send daily work briefings to support team members via Telegram';

    public function handle(
        TeamWorkBriefingService $briefingService,
        TeamTelegramQuietRulesService $quietRules,
        IraCommunicationService $communicationService,
    ): int {
        $sentCount = 0;

        foreach ($briefingService->recipients() as $user) {
            if (! $quietRules->shouldSendDailyBriefing($user)) {
                continue;
            }

            $results = $communicationService->sendTeamDailyBriefing(
                user: $user,
                briefing: $briefingService->buildFor($user),
            );

            $sentCount += count(array_filter(
                $results,
                fn ($notification) => $notification->status->value === 'sent',
            ));
        }

        $this->info("Team daily briefing processed. {$sentCount} message(s) delivered.");

        return self::SUCCESS;
    }
}
