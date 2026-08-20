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

    /*
    |--------------------------------------------------------------------------
    | Cloud inventory index (read-only Administration table)
    |--------------------------------------------------------------------------
    |
    | Written by bin/backup-cloud-inventory.sh on the KVM. The web process
    | reads this sanitized JSON only — never Cloud SSH credentials or paths.
    |
    */

    'cloud_inventory_path' => env(
        'BACKUP_CLOUD_INVENTORY_PATH',
        rtrim(env('BACKUP_STAGING_ROOT', '/var/backups/radium-desk'), '/').'/cloud-inventory.json',
    ),

    'cloud_inventory_limit' => max(1, (int) env('BACKUP_CLOUD_INVENTORY_LIMIT', 10)),

    /*
    |--------------------------------------------------------------------------
    | Restore guidance (Administration display only)
    |--------------------------------------------------------------------------
    */

    'restore_runbook_path' => 'docs/backup-runbook.md',

    'restore_runbook_anchor' => 'how-to-restore-manual',

];
