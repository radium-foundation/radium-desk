<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Last Active Throttle (seconds)
    |--------------------------------------------------------------------------
    |
    | Minimum interval between persisting users.last_active_at updates from
    | recordSystemActivity(). Polling and middleware traffic reuse the loaded
    | user timestamp to skip redundant row updates within this window.
    |
    */

    'last_active_throttle_seconds' => (int) env('TEAM_MEMBER_ACTIVITY_THROTTLE_SECONDS', 60),

];
