<?php

namespace App\Providers;

use App\Contracts\AI\AIProvider;
use App\Listeners\BroadcastNotificationCreated;
use App\Models\DeviceModel;
use App\Models\SettingProduct;
use App\Models\SettingSource;
use App\Models\SystemSetting;
use App\Models\User;
use App\Policies\DashboardPolicy;
use App\Policies\SettingPolicy;
use App\Policies\SystemSettingPolicy;
use App\Services\AI\Providers\NullAIProvider;
use App\Services\Automation\AutomationIdempotencyKeyGenerator;
use App\Services\Automation\AutomationRuntime;
use App\Services\Automation\Handlers\NotificationActionHandler;
use App\Services\GlobalSearch\ServiceCaseGlobalSearchProvider;
use App\Services\GlobalSearchService;
use App\Services\Notifications\Channels\DesktopChannel;
use App\Services\Notifications\Channels\EmailChannel;
use App\Services\Notifications\Channels\TelegramChannel;
use App\Services\Notifications\Channels\WhatsAppChannel;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\RadiumBox\RadiumBoxRequestCache;
use App\Services\SettingService;
use App\Services\SystemSettingsService;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
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
        $this->app->singleton(RadiumBoxRequestCache::class);

        $this->app->singleton(AIProvider::class, function ($app): AIProvider {
            return match (config('ai.provider')) {
                'null' => $app->make(NullAIProvider::class),
                default => $app->make(NullAIProvider::class),
            };
        });

        $this->app->singleton(GlobalSearchService::class, function ($app): GlobalSearchService {
            return new GlobalSearchService([
                $app->make(ServiceCaseGlobalSearchProvider::class),
            ]);
        });

        $this->app->singleton(NotificationDispatcher::class, function ($app): NotificationDispatcher {
            return new NotificationDispatcher(
                $app->make(SystemSettingsService::class),
                [
                    $app->make(WhatsAppChannel::class),
                    $app->make(EmailChannel::class),
                    $app->make(DesktopChannel::class),
                    $app->make(TelegramChannel::class),
                ],
                $app->make(NotificationAuditTrailService::class),
            );
        });

        $this->app->singleton(AutomationRuntime::class, function ($app): AutomationRuntime {
            return new AutomationRuntime(
                $app->make(AutomationIdempotencyKeyGenerator::class),
                [
                    $app->make(NotificationActionHandler::class),
                ],
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->applyApplicationTimezone();

        Event::listen(NotificationSent::class, BroadcastNotificationCreated::class);

        Gate::define('viewDashboardHardware', fn (User $user): bool => app(DashboardPolicy::class)->viewHardware($user));

        Gate::policy(SettingProduct::class, SettingPolicy::class);
        Gate::policy(SettingSource::class, SettingPolicy::class);
        Gate::policy(DeviceModel::class, SettingPolicy::class);
        Gate::policy(SystemSetting::class, SystemSettingPolicy::class);

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

    private function applyApplicationTimezone(): void
    {
        $this->app->booted(function (): void {
            try {
                $timezone = $this->app->make(SettingService::class)->get('general.timezone');

                if (is_string($timezone) && $timezone !== '') {
                    config(['app.timezone' => $timezone]);
                    date_default_timezone_set($timezone);
                }
            } catch (\Throwable) {
                //
            }
        });
    }
}
