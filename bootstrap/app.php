<?php

use App\Enums\QueueWorkerMode;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\TrackTeamMemberActivity;
use App\Infrastructure\Queue\QueueRouting;
use App\Services\Platform\PlatformHealthCache;
use App\Services\SystemSettingsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        $middleware->appendToGroup('web', TrackTeamMemberActivity::class);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Must stay first (no withoutOverlapping). Hostinger cron flock FDs are
        // dropped by bin/schedule-run.sh before PHP starts — see
        // docs/hostinger-scheduler-cron-wrapper.md.
        $schedule->call(function (): void {
            PlatformHealthCache::recordSchedulerHeartbeat();
        })
            ->name('operations:scheduler-heartbeat')
            ->everyMinute();

        // Queue drain: only when QUEUE_WORKER_MODE=scheduler (legacy in-schedule worker).
        // dedicated_cron uses Hostinger Cron #2 — see docs/infrastructure-readiness.md.
        $schedule->command(QueueRouting::scheduledWorkerCommand())
            ->everyMinute()
            ->when(fn (): bool => QueueWorkerMode::fromConfig()->runsViaScheduler())
            ->withoutOverlapping(max(1, (int) config('scheduler.overlap_minutes.every_minute', 2)))
            ->appendOutputTo(storage_path('logs/queue-worker.log'));

        // Phase 10: one in-process dispatcher for former every-minute light jobs
        // (automation-pending, ira telegram flush, outbox, presence) — one artisan
        // boot instead of four. Same Artisan commands / services as before.
        $schedule->command('schedule:light-tick')
            ->everyMinute()
            ->withoutOverlapping(max(1, (int) config('scheduler.overlap_minutes.every_minute', 2)))
            ->appendOutputTo(storage_path('logs/schedule-light-tick.log'));

        $schedule->command('reminders:dispatch-due')
            ->everyMinute()
            ->withoutOverlapping(max(1, (int) config('scheduler.overlap_minutes.every_minute', 2)))
            ->appendOutputTo(storage_path('logs/reminders-dispatch-due.log'));

        // Stagger +4 off :00/:05 pack — same 5-minute cadence (4-59/5 → :04,:09,…).
        $schedule->command('infrastructure:metrics:collect')
            ->cron('4-59/5 * * * *')
            ->when(fn (): bool => (bool) config('infrastructure.metrics_enabled'))
            ->withoutOverlapping(max(1, (int) config('scheduler.overlap_minutes.every_five_minutes', 5)));

        $schedule->command('service-cases:process-deferred-smart-assignment')
            ->cron(sprintf(
                '*/%d * * * *',
                max(1, (int) config('smart_assignment.deferred.schedule_interval_minutes', 5)),
            ))
            ->when(fn (): bool => (bool) config('smart_assignment.enabled', true)
                && (bool) config('smart_assignment.deferred.enabled', true))
            ->withoutOverlapping(max(1, (int) config('scheduler.overlap_minutes.every_five_minutes', 5)))
            ->appendOutputTo(storage_path('logs/deferred-smart-assignment.log'));

        // Light tick: drain dirty slices + time fields + Cashfree KPI merge.
        // Full rebuilds are event-driven (dirty Health/Validation/Repair) or
        // the staggered 15-minute --reconcile safety net below.
        $schedule->command('automation:snapshot')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/automation-snapshot.log'));

        // Stagger +9 off :00/:15 pack — same 15-minute cadence (:09,:24,:39,:54).
        $schedule->command('automation:snapshot --reconcile')
            ->cron('9-59/15 * * * *')
            ->withoutOverlapping(20)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/automation-snapshot.log'));

        $schedule->command('executive:snapshot')
            ->hourly()
            ->withoutOverlapping(max(1, (int) config('scheduler.overlap_minutes.hourly', 55)))
            ->appendOutputTo(storage_path('logs/executive-snapshot.log'));

        // Phase 10: align with zone TTLs (120–300s); default every 5 minutes.
        // Stagger +1 off clock — same cadence (1-59/5 → :01,:06,:11,…).
        $schedule->command('platform:snapshots:warm')
            ->cron(sprintf(
                '1-59/%d * * * *',
                max(1, (int) config('scheduler.platform_snapshots_warm_interval_minutes', 5)),
            ))
            ->withoutOverlapping(max(1, (int) config('scheduler.overlap_minutes.every_five_minutes', 5)))
            ->appendOutputTo(storage_path('logs/platform-snapshots-warm.log'));

        // Background is safe when Cron #1 uses bin/schedule-run.sh (drops host
        // flock FDs before PHP). Do not point Hostinger cron at bare php artisan.
        $schedule->command('inbound-email:sync-gmail')
            ->cron(sprintf(
                '*/%d * * * *',
                max(1, (int) config('inbound_email.gmail.schedule_interval_minutes', 2)),
            ))
            ->when(fn (): bool => (bool) config('inbound_email.enabled')
                && (bool) config('inbound_email.gmail.enabled'))
            ->withoutOverlapping(10)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/inbound-email-gmail-sync.log'));

        $schedule->call(function (): void {
            Artisan::call('attendance:reconcile-days', [
                '--from' => now()->subDay()->toDateString(),
                '--to' => now()->toDateString(),
            ]);
        })
            ->name('attendance:reconcile-days-nightly')
            ->dailyAt('01:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/attendance-reconcile.log'));

        $schedule->command('ira:capture-memory-snapshot')
            ->dailyAt('00:05')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/ira-memory-snapshot.log'));

        $schedule->command('performance-intelligence:snapshot')
            ->dailyAt(config('performance_intelligence.snapshot_time', '00:15'))
            ->when(fn (): bool => (bool) config('performance_intelligence.enabled', false))
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/performance-intelligence-snapshot.log'));

        $schedule->command('ira:send-daily-briefing')
            ->dailyAt(config('ira.communication.daily_briefing_time', '08:00'))
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/ira-daily-briefing.log'));

        $schedule->command('ira:send-ops-digest --period=morning')
            ->dailyAt(config('ira.communication.admin_ops_digest.morning_time', '10:00'))
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/ira-ops-digest.log'));

        $schedule->command('ira:send-ops-digest --period=evening')
            ->dailyAt(config('ira.communication.admin_ops_digest.evening_time', '20:30'))
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/ira-ops-digest.log'));

        $schedule->command('ira:send-ready-queue-digest')
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/ira-ready-queue-digest.log'));

        $schedule->command('workforce:send-short-attendance-evening-review')
            ->dailyAt(config('workforce.short_attendance.evening_review_time', '18:45'))
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/short-attendance-evening-review.log'));

        $schedule->command('ira:send-owner-intelligence --period=morning')
            ->dailyAt(config('ira.communication.owner_morning_report_time', '10:00'))
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/ira-owner-intelligence.log'));

        $schedule->command('ira:send-owner-intelligence --period=evening')
            ->dailyAt(config('ira.communication.owner_evening_report_time', '20:00'))
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/ira-owner-intelligence.log'));

        $schedule->command('ira:send-risk-alerts')
            ->hourly()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/ira-risk-alerts.log'));

        // Stagger +3 off :00/:05 pack — same 5-minute cadence (3-59/5 → :03,:08,…).
        $schedule->command('watchdog:send-critical-alerts')
            ->cron(sprintf('3-59/%d * * * *', max(1, (int) config('ira.watchdog.schedule_interval_minutes', 5))))
            ->when(fn (): bool => (bool) config('ira.watchdog.enabled', true))
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/watchdog-critical-alerts.log'));

        $schedule->command('team-telegram:send-daily-briefings')
            ->everyFifteenMinutes()
            ->when(fn (): bool => (bool) config('team_telegram.enabled', true))
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/team-telegram-daily-briefings.log'));

        $schedule->command('team-telegram:send-slot-reminders')
            ->hourly()
            ->when(fn (): bool => (bool) config('team_telegram.enabled', true))
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/team-telegram-slot-reminders.log'));

        // Stagger +2 off :00/:05 pack — same 5-minute cadence (2-59/5 → :02,:07,…).
        $schedule->command('team-telegram:send-appointment-reminders')
            ->cron(sprintf(
                '2-59/%d * * * *',
                max(1, (int) config('team_telegram.appointment_reminders.schedule_interval_minutes', 5)),
            ))
            ->when(fn (): bool => (bool) config('team_telegram.enabled', true)
                && (bool) config('team_telegram.appointment_reminders.enabled', true))
            ->withoutOverlapping(max(1, (int) config('scheduler.overlap_minutes.every_five_minutes', 5)))
            ->appendOutputTo(storage_path('logs/team-telegram-appointment-reminders.log'));

        $schedule->command('automation:run')
            ->hourly()
            ->when(fn (): bool => app(SystemSettingsService::class)->getBool('automation.scheduler.enabled', false))
            ->withoutOverlapping(max(1, (int) config('scheduler.overlap_minutes.hourly', 55)))
            ->appendOutputTo(storage_path('logs/automation-scheduler.log'));

        // Keep on :00/:15/:30/:45 — anchor for recovery pack stagger.
        $schedule->command('radiumbox:recover-sync')
            ->cron(sprintf('*/%d * * * *', max(1, (int) config('radiumbox.recovery.schedule_interval_minutes', 15))))
            ->when(fn (): bool => (bool) config('radiumbox.recovery.enabled', true))
            ->withoutOverlapping(max(1, (int) config('scheduler.overlap_minutes.every_fifteen_minutes', 15)))
            ->appendOutputTo(storage_path('logs/radiumbox-recovery.log'));

        // Stagger +5 off recover-sync — same 15-minute cadence (:05,:20,:35,:50).
        $schedule->command('missing-serial:process')
            ->cron(sprintf('5-59/%d * * * *', max(1, (int) config('missing_serial.schedule_interval_minutes', 15))))
            ->when(fn (): bool => (bool) config('missing_serial.enabled', true))
            ->withoutOverlapping(max(1, (int) config('scheduler.overlap_minutes.every_fifteen_minutes', 15)))
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/missing-serial-automation.log'));

        // Stagger +7 off recover-sync — same 15-minute cadence (:07,:22,:37,:52).
        $schedule->command('cashfree:auto-recover-missing')
            ->cron(sprintf('7-59/%d * * * *', max(1, (int) config('cashfree.auto_recover.schedule_interval_minutes', 15))))
            ->when(fn (): bool => (bool) config('cashfree.auto_recover.enabled', true))
            ->withoutOverlapping(max(1, (int) config('scheduler.overlap_minutes.every_fifteen_minutes', 15)))
            ->appendOutputTo(storage_path('logs/cashfree-auto-recover.log'));

        // Legacy backfill remains available for manual/admin use.
        // $schedule->command('radiumbox:backfill-orders --limit=50')
        //     ->hourly()
        //     ->withoutOverlapping()
        //     ->appendOutputTo(storage_path('logs/radiumbox-backfill.log'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || ($request->is('service-requests/quick') && ($request->expectsJson() || $request->ajax()))
                || ($request->is('pos/products/search', 'pos/serials/search', 'pos/customers/lookup')
                    && ($request->expectsJson() || $request->ajax())),
        );
    })->create();
