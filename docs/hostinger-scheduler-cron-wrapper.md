# Hostinger scheduler cron wrapper

**Status:** Permanent infrastructure mitigation for Hostinger `flock` + Laravel `runInBackground()` lock inheritance.

## Problem

Hostinger wraps each cron entry approximately as:

```text
flock -w 1 /tmp/cron_lock_<id> timeout -s 9 1800 <your-command>
```

`flock` holds `/tmp/cron_lock_*` and the lock file descriptor is inherited by the child process tree. Laravel’s `inbound-email:sync-gmail` uses `runInBackground()`, so a long or hung sync keeps that FD open after `schedule:run` exits. The next minute’s `flock -w 1` fails within one second and **skips** `schedule:run` entirely — including the scheduler heartbeat and `presence:process-timeouts`.

## Why PHP `php://fd` cannot fix this

A previous in-app helper (`HostCronLockReleaser`) tried:

```php
$stream = fopen('php://fd/'.$fd, 'r+');
fclose($stream);
```

That **does not** release the flock:

1. PHP’s `php://fd/N` implementation **always `dup()`s** the descriptor (`ext/standard/php_fopen_wrapper.c`: `fd = dup((int)fildes_ori)`).
2. `fclose()` closes only the duplicate.
3. The original inherited FD remains open and keeps the advisory lock.
4. On this Hostinger PHP build, `FFI` / `posix_close` are unavailable, so userland PHP cannot call `close(2)` on the original FD either.

Closing write-opened lock FDs from a **shell** with `exec N>&-` (and `exec N<&-` for read-only opens) does release the lock. That belongs in the cron entrypoint, not in Laravel.

## Permanent solution

Cron #1 must invoke [`bin/schedule-run.sh`](../bin/schedule-run.sh):

1. Scan `/proc/$$/fd` for paths matching `cron_lock*` (skip FD 0–2).
2. Close those descriptors with `exec N>&-` / `exec N<&-`.
3. `exec php artisan schedule:run`.

PHP and any `runInBackground()` children therefore never inherit the host flock. Hostinger’s `flock` parent still serializes until the wrapper/`schedule:run` process exits (expected). Queue Cron #2 keeps its **own** lock file and is unchanged.

## Production Cron #1 command

Observed Hostinger wrapper (panel adds `flock` + `timeout` automatically):

```text
/usr/bin/flock -w 1 /tmp/cron_lock_jdK5o9mWc8 timeout -s 9 1800 \
  <YOUR_COMMAND> > /home/u215544208/.logs/cronjob_jdK5o9mWc8 2>&1
```

### Before (vulnerable — do not keep)

In hPanel the stored command was:

```bash
/opt/alt/php84/usr/bin/php /home/u215544208/laravel/radium-desk/artisan schedule:run
```

### After (required)

In Hostinger hPanel → Cron Jobs → edit Cron #1 (`CRONJOBID:jdK5o9mWc8`), set command to:

```bash
/home/u215544208/laravel/radium-desk/bin/schedule-run.sh
```

Optional home symlink (same pattern as `~/queue-worker.sh`):

```bash
ln -sfn /home/u215544208/laravel/radium-desk/bin/schedule-run.sh /home/u215544208/schedule-run.sh
```

Then Cron #1 may call `/home/u215544208/schedule-run.sh` instead.

Frequency: every minute (`* * * * *`). hPanel cron definitions are **not** in user `crontab` and cannot be changed over SSH — update them in the panel UI.

**Do not** point Cron #1 at bare:

```bash
/opt/alt/php84/usr/bin/php .../artisan schedule:run
```
## Rollback

1. In hPanel, change Cron #1 back to:

   ```bash
   /opt/alt/php84/usr/bin/php /home/u215544208/laravel/radium-desk/artisan schedule:run
   ```

2. If a hung `inbound-email:sync-gmail` holds `/tmp/cron_lock_*`, kill that PID (and its `sh -c` wrapper), then:

   ```bash
   cd /home/u215544208/laravel/radium-desk
   /opt/alt/php84/usr/bin/php artisan schedule:clear-cache
   ```

3. Redeploy a previous git revision only if you also need the old PHP releaser code (not recommended — it did not work).

## Deployment steps

1. Deploy code so `bin/schedule-run.sh` exists and is executable (`chmod +x`).
2. Update Hostinger Cron #1 to the wrapper path above.
3. Kill any existing processes that still hold `/tmp/cron_lock_*` (typically hung Gmail syncs started under the old entrypoint).
4. Run `php artisan schedule:clear-cache` if Gmail/other `withoutOverlapping` mutexes look stuck.
5. Validate with the checklist below.

## Validation checklist

- [ ] `ls -l bin/schedule-run.sh` shows executable bit
- [ ] Cron #1 command in hPanel is the wrapper (not bare `php artisan schedule:run`)
- [ ] Over ~3 minutes, `PlatformHealthCache` scheduler heartbeat advances every minute
- [ ] `presence:process-timeouts` log / presence last-run cache advances every minute
- [ ] `inbound-email:sync-gmail` may still appear as a background process (`runInBackground`)
- [ ] After `schedule:run` exits, no Gmail/`sh -c` child has FD → `/tmp/cron_lock_*` (`ls -l /proc/<pid>/fd`)
- [ ] Super Admin Platform Health → Scheduler shows healthy (&lt; 3 minutes)

## Related

- [`docs/infrastructure-readiness.md`](infrastructure-readiness.md) — Cron #1 / Cron #2 topology
- [`docs/production-stuck-scheduler-recovery-runbook.md`](production-stuck-scheduler-recovery-runbook.md) — incident recovery
