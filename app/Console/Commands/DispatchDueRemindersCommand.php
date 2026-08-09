<?php

namespace App\Console\Commands;

use App\Services\Reminders\ReminderDispatchService;
use Illuminate\Console\Command;

class DispatchDueRemindersCommand extends Command
{
    protected $signature = 'reminders:dispatch-due
                            {--limit= : Maximum number of due reminders to claim this run}';

    protected $description = 'Dispatch due operator reminders into the Laravel notification center';

    public function handle(ReminderDispatchService $dispatchService): int
    {
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : null;

        $stats = $dispatchService->dispatchDue($limit);

        $this->info(sprintf(
            'Reminders: claimed=%d dispatched=%d skipped=%d failed=%d retried=%d',
            $stats['claimed'],
            $stats['dispatched'],
            $stats['skipped'],
            $stats['failed'],
            $stats['retried'],
        ));

        return self::SUCCESS;
    }
}
