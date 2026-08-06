<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class ShortAttendanceEveningReviewNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $pendingToday,
        private readonly Carbon $workDate,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $count = $this->pendingToday;
        $employeesLabel = $count === 1 ? '1 employee' : "{$count} employees";

        return [
            'title' => "Today's Short Attendance Review",
            'message' => "Pending: {$employeesLabel}",
            'url' => route('workforce-management.short-attendance.index', [
                'period' => 'today',
                'status' => 'pending',
            ]),
            'work_date' => $this->workDate->toDateString(),
            'pending_today' => $count,
            // Reserved for future channels / evidence workflows.
            'kind' => 'short_attendance_evening_review',
        ];
    }
}
