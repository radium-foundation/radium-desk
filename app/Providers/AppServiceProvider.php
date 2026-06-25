<?php

namespace App\Providers;

use App\Models\SettingProduct;
use App\Models\SettingSource;
use App\Policies\SettingPolicy;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(SettingProduct::class, SettingPolicy::class);
        Gate::policy(SettingSource::class, SettingPolicy::class);

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
