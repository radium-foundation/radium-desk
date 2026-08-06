<?php

namespace App\Console\Commands;

use App\Services\Workforce\ShortAttendance\ShortAttendanceReviewService;
use Illuminate\Console\Command;

class SendShortAttendanceEveningReviewCommand extends Command
{
    protected $signature = 'workforce:send-short-attendance-evening-review';

    protected $description = 'Notify designated HR of today\'s pending Short Attendance reviews (skip when zero)';

    public function handle(ShortAttendanceReviewService $reviewService): int
    {
        $result = $reviewService->sendEveningReviewNotification();

        if ($result['pending_today'] === 0) {
            $this->info('No pending Short Attendance reviews for today — notification skipped.');

            return self::SUCCESS;
        }

        if (! $result['sent']) {
            $this->warn(sprintf(
                '%d pending today, but no eligible HR recipient was notified.',
                $result['pending_today'],
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Evening Short Attendance review notification sent to user #%d (%d pending).',
            (int) $result['recipient_id'],
            $result['pending_today'],
        ));

        return self::SUCCESS;
    }
}
