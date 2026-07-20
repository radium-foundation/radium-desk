<?php

namespace App\Providers;

use App\Data\Platform\PlatformSectionDefinition;
use App\Enums\PlatformDashboardSection;
use App\Services\Platform\Cards\Executive\ActiveAgentsCardProvider;
use App\Services\Platform\Cards\Executive\AppointmentsTodayCardProvider;
use App\Services\Platform\Cards\Executive\CriticalCasesCardProvider;
use App\Services\Platform\Cards\Executive\CustomersWaitingCardProvider;
use App\Services\Platform\Cards\Executive\OpenCasesCardProvider;
use App\Services\Platform\Cards\Executive\OrdersTodayCardProvider;
use App\Services\Platform\Cards\Executive\RefundQueueCardProvider;
use App\Services\Platform\Cards\Executive\ResolvedTodayCardProvider;
use App\Services\Platform\Cards\PlaceholderSectionCardProvider;
use App\Services\Platform\Cards\PlatformHealthCardProvider;
use App\Services\Platform\DashboardManifest;
use App\Services\Platform\Health\AutomationHealthProvider;
use App\Services\Platform\Health\CacheHealthProvider;
use App\Services\Platform\Health\DatabaseHealthProvider;
use App\Services\Platform\Health\PresenceHealthProvider;
use App\Services\Platform\Health\QueueHealthProvider;
use App\Services\Platform\Health\SchedulerHealthProvider;
use App\Services\Platform\Health\StorageHealthProvider;
use App\Services\Platform\PlatformCardRegistry;
use App\Services\Platform\PlatformHealthRegistry;
use App\Services\Platform\PlatformSectionRegistry;
use Illuminate\Support\ServiceProvider;

class PlatformDashboardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PlatformSectionRegistry::class);
        $this->app->singleton(PlatformCardRegistry::class);

        $this->app->singleton(PlatformHealthRegistry::class, function ($app): PlatformHealthRegistry {
            $registry = new PlatformHealthRegistry;

            $registry->register($app->make(SchedulerHealthProvider::class));
            $registry->register($app->make(PresenceHealthProvider::class));
            $registry->register($app->make(QueueHealthProvider::class));
            $registry->register($app->make(AutomationHealthProvider::class));
            $registry->register($app->make(DatabaseHealthProvider::class));
            $registry->register($app->make(CacheHealthProvider::class));
            $registry->register($app->make(StorageHealthProvider::class));

            return $registry;
        });

        $this->app->singleton(DashboardManifest::class, function ($app): DashboardManifest {
            return new DashboardManifest(
                sectionRegistry: $app->make(PlatformSectionRegistry::class),
                cardRegistry: $app->make(PlatformCardRegistry::class),
            );
        });
    }

    public function boot(): void
    {
        $manifest = $this->app->make(DashboardManifest::class);

        foreach ($this->coreSections() as $section) {
            $manifest->registerSection($section);
        }

        foreach ($this->executiveCards() as $cardClass) {
            $manifest->registerCard($this->app->make($cardClass));
        }

        $manifest->registerCard($this->app->make(PlatformHealthCardProvider::class));

        foreach ($this->placeholderCards() as $placeholder) {
            $manifest->registerCard($placeholder);
        }
    }

    /**
     * @return list<PlatformSectionDefinition>
     */
    private function coreSections(): array
    {
        return array_map(
            fn (PlatformDashboardSection $section): PlatformSectionDefinition => new PlatformSectionDefinition(
                id: $section->value,
                title: $section->label(),
                priority: $section->sortOrder(),
                icon: $this->sectionIcon($section),
            ),
            PlatformDashboardSection::ordered(),
        );
    }

    /**
     * @return list<class-string>
     */
    private function executiveCards(): array
    {
        return [
            OpenCasesCardProvider::class,
            CriticalCasesCardProvider::class,
            RefundQueueCardProvider::class,
            ActiveAgentsCardProvider::class,
            CustomersWaitingCardProvider::class,
            OrdersTodayCardProvider::class,
            ResolvedTodayCardProvider::class,
            AppointmentsTodayCardProvider::class,
        ];
    }

    /**
     * @return list<PlaceholderSectionCardProvider>
     */
    private function placeholderCards(): array
    {
        return [
            new PlaceholderSectionCardProvider(
                sectionId: PlatformDashboardSection::Operations->value,
                cardTitle: 'Business Operations',
                priority: 10,
                upcomingCards: ['Open Orders', 'Pending Verification', 'Serial Requests', 'Appointments', 'Delayed Orders', 'Escalations'],
                icon: 'bi-sliders',
            ),
            new PlaceholderSectionCardProvider(
                sectionId: PlatformDashboardSection::Customers->value,
                cardTitle: 'Customer Operations',
                priority: 10,
                upcomingCards: ['Open Cases', 'Waiting Customer', 'Waiting Internal', 'Overdue', 'SLA Risk', 'Top Categories'],
                icon: 'bi-person-badge',
            ),
            new PlaceholderSectionCardProvider(
                sectionId: PlatformDashboardSection::Workforce->value,
                cardTitle: 'Workforce',
                priority: 10,
                upcomingCards: ['Active Now', 'Late Login', 'Attendance Exceptions', 'Leave Today', 'Top Performers', 'Idle Users'],
                icon: 'bi-people',
            ),
            new PlaceholderSectionCardProvider(
                sectionId: PlatformDashboardSection::Communications->value,
                cardTitle: 'Communications',
                priority: 10,
                upcomingCards: ['Email', 'WhatsApp', 'Telegram', 'Failed Messages', 'Pending Queue'],
                icon: 'bi-chat-dots',
            ),
            new PlaceholderSectionCardProvider(
                sectionId: PlatformDashboardSection::Finance->value,
                cardTitle: 'Finance',
                priority: 10,
                upcomingCards: ['Refund Queue', 'Pending Payments', 'Cashfree', 'Settlement', 'Failures'],
                icon: 'bi-cash-stack',
            ),
            new PlaceholderSectionCardProvider(
                sectionId: PlatformDashboardSection::Automation->value,
                cardTitle: 'Automation',
                priority: 10,
                upcomingCards: ['IRA', 'Scheduled Rules', 'Queue', 'Failures', 'Retry Queue'],
                icon: 'bi-robot',
            ),
            new PlaceholderSectionCardProvider(
                sectionId: PlatformDashboardSection::System->value,
                cardTitle: 'System',
                priority: 10,
                upcomingCards: ['Storage', 'Cache', 'Queue', 'Database', 'Scheduler', 'Logs'],
                icon: 'bi-gear',
            ),
        ];
    }

    private function sectionIcon(PlatformDashboardSection $section): string
    {
        return match ($section) {
            PlatformDashboardSection::Executive => 'bi-speedometer2',
            PlatformDashboardSection::PlatformHealth => 'bi-heart-pulse',
            PlatformDashboardSection::Operations => 'bi-sliders',
            PlatformDashboardSection::Workforce => 'bi-people',
            PlatformDashboardSection::Customers => 'bi-person-badge',
            PlatformDashboardSection::Automation => 'bi-robot',
            PlatformDashboardSection::Finance => 'bi-cash-stack',
            PlatformDashboardSection::Communications => 'bi-chat-dots',
            PlatformDashboardSection::System => 'bi-gear',
        };
    }
}
