<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        View::composer('layouts.partials.navbar', function ($view): void {
            $user = auth()->user();

            if ($user === null) {
                return;
            }

            $unreadCount = $user->unreadNotifications()->count();

            $view->with([
                'notificationUnreadCount' => $unreadCount,
                'notificationUnreadBadge' => match (true) {
                    $unreadCount <= 0 => null,
                    $unreadCount > 99 => '99+',
                    default => (string) $unreadCount,
                },
                'latestNotifications' => $user->notifications()->latest()->limit(10)->get(),
            ]);
        });
    }
}
