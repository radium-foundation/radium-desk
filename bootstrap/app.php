<?php

use App\Http\Middleware\EnsureUserIsActive;
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

        // Phase 2: enable when ready to recover delayed RadiumBox product details automatically.
        // $schedule->command('radiumbox:backfill-orders --limit=50')
        //     ->hourly()
        //     ->withoutOverlapping()
        //     ->appendOutputTo(storage_path('logs/radiumbox-backfill.log'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
