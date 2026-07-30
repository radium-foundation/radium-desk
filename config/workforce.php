<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Extra Day Qualification (Milestone 5)
    |--------------------------------------------------------------------------
    |
    | When disabled (default), ExtraQualificationEngine mirrors today's Extra
    | attendance status and publishes no events. Attendance is never mutated.
    |
    */
    'extra_qualification' => [
        'enabled' => (bool) env('WORKFORCE_EXTRA_QUALIFICATION_ENABLED', false),
    ],

];
