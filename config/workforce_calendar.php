<?php

return [

    /*
    | How many calendar days before today a leave start_date may be set.
    | 0 = today onward only. 2 = up to two days retroactive (blueprint default).
    */
    'retroactive_leave_days' => (int) env('WORKFORCE_RETROACTIVE_LEAVE_DAYS', 2),

    'default_work_start' => '09:00',
    'default_work_end' => '18:00',
    'default_lunch_start' => '13:30',
    'default_lunch_end' => '14:00',
    'default_short_break_count' => 2,
    'default_short_break_minutes' => 10,

    /*
    | Carbon day-of-week integers (0 = Sunday, 6 = Saturday).
    | Configurable per employee via team_member_work_schedules.weekly_off_days.
    */
    'default_weekly_off_days' => [0],

    'attendance_calculator_version' => 1,

    /*
    | Phase 1 short attendance: closed working days with worked minutes below this
    | threshold (and above 0) are marked Short Attendance (payroll = Absent).
    | 0 worked minutes remains plain Absent. Leave / holiday / week-off unchanged.
    */
    'short_attendance_minutes' => max(
        1,
        (int) env('ATTENDANCE_SHORT_ATTENDANCE_MINUTES', 30),
    ),

    /*
    | July 2026 HR go-live: presence tracking started 2026-07-05.
    | One-shot backfill window for pre-go-live working days (not a permanent rule).
    */
    'july_golive_attendance_backfill' => [
        'from' => '2026-07-01',
        'to' => '2026-07-04',
        'go_live' => '2026-07-05',
    ],

    'weekday_labels' => [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ],

];
