<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\TrackTeamMemberActivity;
use App\Services\SystemSettingsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
        $schedule->command('queue:work --stop-when-empty --max-time=55')
            ->everyMinute()
            ->when(fn (): bool => (bool) config('infrastructure.queue_cron_worker_enabled'))
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/queue-worker.log'));

        $schedule->command('infrastructure:metrics:collect')
            ->everyFiveMinutes()
            ->when(fn (): bool => (bool) config('infrastructure.metrics_enabled'))
            ->withoutOverlapping();

        $schedule->command('service-cases:process-automation-pending')
            ->everyMinute()
            ->when(fn (): bool => (bool) config('service_case_assignment.automation_grace_period_enabled', true))
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/automation-pending-assignments.log'));

        $schedule->command('automation:snapshot')
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/automation-snapshot.log'));

        $schedule->command('outbox:process')
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/outbox-processor.log'));

        $schedule->command('presence:process-timeouts')
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/presence-timeouts.log'));

        $schedule->command('ira:capture-memory-snapshot')
            ->dailyAt('00:05')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/ira-memory-snapshot.log'));

        $schedule->command('ira:send-daily-briefing')
            ->dailyAt(config('ira.communication.daily_briefing_time', '08:00'))
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/ira-daily-briefing.log'));

        $schedule->command('ira:send-ops-digest --period=open')
            ->dailyAt('08:15')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/ira-ops-digest.log'));

        $schedule->command('ira:send-ops-digest --period=close')
            ->dailyAt('18:30')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/ira-ops-digest.log'));

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

        $schedule->command('automation:run')
            ->hourly()
            ->when(fn (): bool => app(SystemSettingsService::class)->getBool('automation.scheduler.enabled', false))
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/automation-scheduler.log'));

        $schedule->command('radiumbox:recover-sync')
            ->cron(sprintf('*/%d * * * *', max(1, (int) config('radiumbox.recovery.schedule_interval_minutes', 15))))
            ->when(fn (): bool => (bool) config('radiumbox.recovery.enabled', true))
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/radiumbox-recovery.log'));

        $schedule->command('missing-serial:process')
            ->cron(sprintf('*/%d * * * *', max(1, (int) config('missing_serial.schedule_interval_minutes', 15))))
            ->when(fn (): bool => (bool) config('missing_serial.enabled', true))
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/missing-serial-automation.log'));

        $schedule->command('cashfree:auto-recover-missing')
            ->cron(sprintf('*/%d * * * *', max(1, (int) config('cashfree.auto_recover.schedule_interval_minutes', 5))))
            ->when(fn (): bool => (bool) config('cashfree.auto_recover.enabled', true))
            ->withoutOverlapping()
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
                || ($request->is('service-requests/quick') && $request->expectsJson()),
        );
    })->create();
