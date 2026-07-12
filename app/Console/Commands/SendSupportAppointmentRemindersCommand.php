<?php

namespace App\Console\Commands;

use App\Services\Operations\AppointmentReminderExecutionService;
use App\Services\Operations\SupportAppointmentReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendSupportAppointmentRemindersCommand extends Command
{
    protected $signature = 'team-telegram:send-appointment-reminders';

    protected $description = 'Send per-appointment Telegram reminders to assigned engineers';

    public function handle(
        SupportAppointmentReminderService $reminderService,
        AppointmentReminderExecutionService $executionService,
    ): int {
        $processed = 0;
        $sent = 0;
        $skipped = 0;

        foreach ($reminderService->dueReminders() as $candidate) {
            $processed++;
            $result = $executionService->execute($candidate);

            if ($result->telegramSent) {
                $sent++;
            }

            if ($result->wasSkipped()) {
                $skipped++;
            }

            Log::info('team_telegram.appointment_reminder', [
                'automation_type' => AppointmentReminderExecutionService::POLICY_KEY,
                'appointment_id' => $candidate->appointment->id,
                'engineer_id' => $candidate->engineer->id,
                'threshold_minutes' => $candidate->thresholdMinutes,
                'execution_id' => $result->execution->id,
                'execution_status' => $result->status->value,
                'telegram_sent' => $result->telegramSent,
                'skipped_existing' => $result->skippedExisting,
            ]);
        }

        $this->info("Appointment reminders processed. {$processed} evaluated, {$sent} delivered, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
