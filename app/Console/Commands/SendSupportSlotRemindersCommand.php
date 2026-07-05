<?php

namespace App\Console\Commands;

use App\Enums\SupportAppointmentTimeSlot;
use App\Services\Operations\IraCommunicationService;
use App\Services\Operations\SupportSlotReminderService;
use App\Services\Operations\TeamTelegramQuietRulesService;
use Illuminate\Console\Command;

class SendSupportSlotRemindersCommand extends Command
{
    protected $signature = 'team-telegram:send-slot-reminders';

    protected $description = 'Send support slot start reminders to team members via Telegram';

    public function handle(
        SupportSlotReminderService $slotReminderService,
        TeamTelegramQuietRulesService $quietRules,
        IraCommunicationService $communicationService,
    ): int {
        $sentCount = 0;

        foreach (SupportAppointmentTimeSlot::cases() as $slot) {
            foreach ($slotReminderService->recipients() as $user) {
                if (! $quietRules->shouldSendSlotReminder($user, $slot)) {
                    continue;
                }

                $items = $slotReminderService->itemsFor($user, $slot);

                if ($items === []) {
                    continue;
                }

                $results = $communicationService->sendSupportSlotReminder(
                    user: $user,
                    slot: $slot,
                    items: $items,
                );

                $sentCount += count(array_filter(
                    $results,
                    fn ($notification) => $notification->status->value === 'sent',
                ));
            }
        }

        $this->info("Support slot reminders processed. {$sentCount} message(s) delivered.");

        return self::SUCCESS;
    }
}
