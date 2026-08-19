<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Local backup staging root
    |--------------------------------------------------------------------------
    |
    | Must match BACKUP_STAGING_ROOT used by bin/backup-run.sh on the KVM.
    | The web process reads manifest.json files only — never encrypted artifacts.
    |
    */

    'staging_root' => env('BACKUP_STAGING_ROOT', '/var/backups/radium-desk'),

    /*
    |--------------------------------------------------------------------------
    | Backup history limit (Administration status page)
    |--------------------------------------------------------------------------
    */

    'history_limit' => max(1, (int) env('BACKUP_STATUS_HISTORY_LIMIT', 10)),

    /*
    |--------------------------------------------------------------------------
    | Scheduled backup times (display only)
    |--------------------------------------------------------------------------
    */

    'schedule_label' => env('BACKUP_SCHEDULE_LABEL', '02:00 IST and 14:00 IST'),

];
