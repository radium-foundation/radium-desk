<?php

namespace App\Console\Commands;

use App\Data\Operations\SupportAppointmentReminderDiagnosticCollector;
use App\Data\Operations\SupportAppointmentReminderDiagnosticEntry;
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
        $collector = new SupportAppointmentReminderDiagnosticCollector;
        $collector->verbose = $this->output->isVerbose();

        $delivered = 0;
        $skipped = 0;

        foreach ($reminderService->dueReminders(collector: $collector) as $candidate) {
            $result = $executionService->execute($candidate);

            if ($result->telegramSent) {
                $delivered++;
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

        $this->renderDiagnostics($collector, $delivered, $skipped);

        return self::SUCCESS;
    }

    private function renderDiagnostics(
        SupportAppointmentReminderDiagnosticCollector $collector,
        int $delivered,
        int $skipped,
    ): void {
        $diagnostics = $collector->toDiagnostics();

        $this->newLine();
        $this->line('Appointment Reminder Diagnostics');
        $this->newLine();

        if (! $diagnostics->globalEnabled) {
            $this->warn('Appointment reminders are globally disabled.');
            $this->newLine();
        }

        $this->line($this->formatMetric('Scheduled appointments', $diagnostics->scheduledAppointments));
        $this->line($this->formatMetric("Today's appointments", $diagnostics->todaysAppointments));
        $this->line($this->formatMetric('With assigned engineer', $diagnostics->withAssignedEngineer));
        $this->line($this->formatMetric('Passed quiet rules', $diagnostics->passedQuietRules));
        $this->line($this->formatMetric('Valid slot configuration', $diagnostics->validSlotConfiguration));
        $this->line($this->formatMetric('Matched reminder window', $diagnostics->matchedReminderWindow));
        $this->newLine();
        $this->line($this->formatMetric('Delivered', $delivered));
        $this->line($this->formatMetric('Skipped', $skipped));

        if ($collector->verbose) {
            $this->renderVerboseEntries($diagnostics->verboseEntries);
        }
    }

    private function formatMetric(string $label, int $value): string
    {
        return sprintf('%-30s %d', $label.':', $value);
    }

    /**
     * @param  list<SupportAppointmentReminderDiagnosticEntry>  $entries
     */
    private function renderVerboseEntries(array $entries): void
    {
        if ($entries === []) {
            $this->newLine();
            $this->line('No appointments to explain for today.');

            return;
        }

        foreach ($entries as $entry) {
            $this->newLine();
            $this->line("Appointment #{$entry->appointmentId}");

            foreach ($entry->checks as $check => $passed) {
                $this->line(sprintf('%s %s', $passed ? '✓' : '✗', $check));

                if (! $passed) {
                    break;
                }
            }

            if ($entry->failureReason !== null) {
                $this->newLine();
                $this->line('Reason:');
                $this->line($entry->failureReason);
            }

            $detailLines = $entry->detailLines();

            if ($detailLines !== []) {
                $this->newLine();

                foreach ($detailLines as $label => $value) {
                    $this->line($label.':');
                    $this->line($value);
                }
            }
        }
    }
}
