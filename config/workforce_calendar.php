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
