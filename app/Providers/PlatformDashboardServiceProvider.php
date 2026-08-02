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
use App\Services\Platform\Alerts\Contributors\ExecutiveSnapshotAlertContributor;
use App\Services\Platform\Alerts\Contributors\IntegrationHealthAlertContributor;
use App\Services\Platform\Alerts\Contributors\PlatformHealthAlertContributor;
use App\Services\Platform\Alerts\PlatformAlertAggregator;
use App\Services\Platform\Alerts\PlatformAlertRegistry;
use App\Services\Platform\Health\Contributors\ExecutiveSnapshotContributionProvider;
use App\Services\Platform\Health\Contributors\IntegrationHealthContributionProvider;
use App\Services\Platform\Health\Contributors\PlatformHealthContributionProvider;
use App\Services\Platform\Health\PlatformOverallHealthRegistry;
use App\Services\Platform\Health\PlatformOverallHealthService;
use App\Services\Platform\Warmers\AutomationSnapshotWarmer;
use App\Services\Platform\Warmers\CommunicationsSnapshotWarmer;
use App\Services\Platform\Warmers\CriticalAlertsSnapshotWarmer;
use App\Services\Platform\Warmers\ExecutiveSnapshotWarmer;
use App\Services\Platform\Warmers\FinanceSnapshotWarmer;
use App\Services\Platform\Warmers\IntegrationHealthSnapshotWarmer;
use App\Services\Platform\Warmers\OperationsSnapshotWarmer;
use App\Services\Platform\Warmers\PerformanceSnapshotWarmer;
use App\Services\Platform\Warmers\PlatformHealthSnapshotWarmer;
use App\Services\Platform\Warmers\PlatformSnapshotWarmerRegistry;
use App\Services\Platform\Warmers\PlatformSnapshotWarmingService;
use App\Services\Platform\Zones\AutomationZone;
use App\Services\Platform\Zones\CommunicationsZone;
use App\Services\Platform\Zones\CriticalAlertsZone;
use App\Services\Platform\Zones\ExecutiveSnapshotZone;
use App\Services\Platform\Zones\FinanceOverviewZone;
use App\Services\Platform\Zones\IntegrationHealthZone;
use App\Services\Platform\Zones\OperationsOverviewZone;
use App\Services\Platform\Zones\PerformanceZone;
use App\Services\Platform\Zones\PlatformHealthZone;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use App\Services\Platform\Zones\ToolsZone;
use Illuminate\Support\ServiceProvider;

class PlatformDashboardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PlatformSectionRegistry::class);
        $this->app->singleton(PlatformCardRegistry::class);
        $this->app->singleton(PlatformZoneSnapshotStore::class);
        $this->app->singleton(PlatformZoneRegistry::class);
        $this->app->singleton(PlatformAlertRegistry::class);
        $this->app->singleton(PlatformAlertAggregator::class);
        $this->app->singleton(PlatformOverallHealthRegistry::class);
        $this->app->singleton(PlatformOverallHealthService::class);
        $this->app->singleton(PlatformSnapshotWarmerRegistry::class);
        $this->app->singleton(PlatformSnapshotWarmingService::class);

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

        $zoneRegistry = $this->app->make(PlatformZoneRegistry::class);

        foreach ($this->zones() as $zoneClass) {
            $zoneRegistry->register($this->app->make($zoneClass));
        }

        $alertRegistry = $this->app->make(PlatformAlertRegistry::class);
        foreach ($this->alertContributors() as $contributorClass) {
            $alertRegistry->register($this->app->make($contributorClass));
        }

        $healthRegistry = $this->app->make(PlatformOverallHealthRegistry::class);
        foreach ($this->healthContributors() as $contributorClass) {
            $healthRegistry->register($this->app->make($contributorClass));
        }

        $warmerRegistry = $this->app->make(PlatformSnapshotWarmerRegistry::class);
        foreach ($this->snapshotWarmers() as $warmerClass) {
            $warmerRegistry->register($this->app->make($warmerClass));
        }
    }

    /**
     * @return list<class-string>
     */
    private function alertContributors(): array
    {
        return [
            PlatformHealthAlertContributor::class,
            IntegrationHealthAlertContributor::class,
            ExecutiveSnapshotAlertContributor::class,
        ];
    }

    /**
     * @return list<class-string>
     */
    private function healthContributors(): array
    {
        return [
            PlatformHealthContributionProvider::class,
            IntegrationHealthContributionProvider::class,
            ExecutiveSnapshotContributionProvider::class,
        ];
    }

    /**
     * @return list<class-string>
     */
    private function snapshotWarmers(): array
    {
        return [
            PlatformHealthSnapshotWarmer::class,
            ExecutiveSnapshotWarmer::class,
            IntegrationHealthSnapshotWarmer::class,
            CriticalAlertsSnapshotWarmer::class,
            PerformanceSnapshotWarmer::class,
            AutomationSnapshotWarmer::class,
            CommunicationsSnapshotWarmer::class,
            FinanceSnapshotWarmer::class,
            OperationsSnapshotWarmer::class,
        ];
    }

    /**
     * @return list<class-string>
     */
    private function zones(): array
    {
        return [
            CriticalAlertsZone::class,
            ExecutiveSnapshotZone::class,
            PlatformHealthZone::class,
            IntegrationHealthZone::class,
            PerformanceZone::class,
            AutomationZone::class,
            CommunicationsZone::class,
            FinanceOverviewZone::class,
            OperationsOverviewZone::class,
            ToolsZone::class,
        ];
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
     * S1: MC-18–MC-24 deep-link workspace cards (existing routes only).
     *
     * @return list<PlaceholderSectionCardProvider>
     */
    private function placeholderCards(): array
    {
        return [
            new PlaceholderSectionCardProvider(
                sectionId: PlatformDashboardSection::Operations->value,
                cardTitle: 'Business Operations',
                priority: 10,
                workspaceLinks: [
                    [
                        'label' => 'Operations Control Center',
                        'route' => 'admin.operations.index',
                        'description' => 'Live ops health, support load, and team status.',
                    ],
                    [
                        'label' => 'Today',
                        'route' => 'admin.operations.index',
                        'params' => ['hub_tab' => 'today'],
                        'description' => 'Support intelligence for the current day.',
                    ],
                    [
                        'label' => 'Performance',
                        'route' => 'admin.operations.index',
                        'params' => ['hub_tab' => 'performance'],
                        'description' => 'Queues, IVR, and operational metrics.',
                    ],
                ],
                detailRoute: ['route' => 'admin.operations.index'],
                permission: 'operations-dashboard.view',
                icon: 'bi-sliders',
                message: 'Open Control Center for live operational command.',
            ),
            new PlaceholderSectionCardProvider(
                sectionId: PlatformDashboardSection::Customers->value,
                cardTitle: 'Customer Operations',
                priority: 10,
                workspaceLinks: [
                    [
                        'label' => 'Operator Dashboard',
                        'route' => 'dashboard',
                        'description' => 'Ready Queue, appointments, and My Activity.',
                    ],
                    [
                        'label' => 'Service Cases',
                        'route' => 'incidents.index',
                        'description' => 'Browse and filter open customer cases.',
                    ],
                    [
                        'label' => 'Waiting Customers',
                        'route' => 'dashboard',
                        'params' => ['queue' => 'waiting_customer'],
                        'description' => 'Customers waiting for a response.',
                    ],
                ],
                detailRoute: ['route' => 'dashboard'],
                permission: 'incidents.view',
                icon: 'bi-person-badge',
                message: 'Jump into operator queues and case workspaces.',
            ),
            new PlaceholderSectionCardProvider(
                sectionId: PlatformDashboardSection::Workforce->value,
                cardTitle: 'Workforce',
                priority: 10,
                workspaceLinks: [
                    [
                        'label' => 'Workforce Overview',
                        'route' => 'workforce.index',
                        'description' => 'Team capacity, presence, and attention items.',
                    ],
                    [
                        'label' => 'Team Performance',
                        'route' => 'admin.workforce.performance.index',
                        'description' => 'Attendance, presence, and customer-work metrics.',
                    ],
                    [
                        'label' => 'Leave Management',
                        'route' => 'leave-requests.index',
                        'description' => 'Pending and approved leave for planning.',
                    ],
                ],
                detailRoute: ['route' => 'workforce.index'],
                permission: 'workforce360.viewTeam',
                icon: 'bi-people',
                message: 'Open the Control Center workforce workspace.',
            ),
            new PlaceholderSectionCardProvider(
                sectionId: PlatformDashboardSection::Communications->value,
                cardTitle: 'Communications',
                priority: 10,
                workspaceLinks: [
                    [
                        'label' => 'Ops System Health',
                        'route' => 'admin.operations.index',
                        'params' => ['hub_tab' => 'system'],
                        'description' => 'Notification failures and recent channel activity.',
                    ],
                    [
                        'label' => 'Audit Logs',
                        'route' => 'audit-logs.index',
                        'description' => 'System activity and communication-related events.',
                    ],
                ],
                detailRoute: [
                    'route' => 'admin.operations.index',
                    'params' => ['hub_tab' => 'system'],
                ],
                permission: 'operations-dashboard.view',
                icon: 'bi-chat-dots',
                message: 'Review communication health from existing ops surfaces.',
            ),
            new PlaceholderSectionCardProvider(
                sectionId: PlatformDashboardSection::Finance->value,
                cardTitle: 'Finance',
                priority: 10,
                workspaceLinks: [
                    [
                        'label' => 'Refund Queue',
                        'route' => 'refunds.index',
                        'params' => ['status' => 'pending'],
                        'description' => 'Pending refunds awaiting action.',
                    ],
                    [
                        'label' => 'Webhook Explorer',
                        'route' => 'cashfree.webhook-explorer.index',
                        'description' => 'Inspect Cashfree payment webhook payloads.',
                    ],
                    [
                        'label' => 'Cashfree Health',
                        'route' => 'admin.platform.index',
                        'fragment' => 'platform-zone-integration_health',
                        'description' => 'Payment integration diagnostics on Platform.',
                    ],
                ],
                detailRoute: [
                    'route' => 'refunds.index',
                    'params' => ['status' => 'pending'],
                ],
                permission: null,
                icon: 'bi-cash-stack',
                message: 'Reach finance and Cashfree tools without leaving the platform workspace.',
            ),
            new PlaceholderSectionCardProvider(
                sectionId: PlatformDashboardSection::Automation->value,
                cardTitle: 'Automation',
                priority: 10,
                workspaceLinks: [
                    [
                        'label' => 'Automation Hub',
                        'route' => 'admin.operations.index',
                        'params' => ['hub_tab' => 'automation'],
                        'description' => 'Health and pipeline in one Control Center tab.',
                    ],
                    [
                        'label' => 'Automation Health',
                        'route' => 'admin.operations.automation-health',
                        'description' => 'Execution ledger and failure forensics.',
                    ],
                    [
                        'label' => 'Automation Pipeline',
                        'route' => 'admin.automation.index',
                        'description' => 'Queues, validation, and pipeline activity.',
                    ],
                ],
                detailRoute: [
                    'route' => 'admin.operations.index',
                    'params' => ['hub_tab' => 'automation'],
                ],
                permission: 'automation-operations.view',
                icon: 'bi-robot',
                message: 'Reuse the shared AutomationExecutionReadModel surfaces.',
            ),
            new PlaceholderSectionCardProvider(
                sectionId: PlatformDashboardSection::System->value,
                cardTitle: 'System',
                priority: 10,
                workspaceLinks: [
                    [
                        'label' => 'Platform Health',
                        'route' => 'admin.platform.index',
                        'fragment' => 'platform-health',
                        'description' => 'Scheduler, queue, cache, database, and storage probes.',
                    ],
                    [
                        'label' => 'System Settings',
                        'route' => 'admin.system-settings.index',
                        'description' => 'Realtime, feature flags, and integration toggles.',
                    ],
                    [
                        'label' => 'Realtime',
                        'route' => 'admin.system-settings.index',
                        'fragment' => 'realtime-settings-card',
                        'description' => 'Broadcast provider and connection health.',
                    ],
                    [
                        'label' => 'Integrations',
                        'route' => 'admin.platform.index',
                        'fragment' => 'platform-health',
                        'description' => 'RadiumBox, Cashfree, Gmail, and messaging health.',
                    ],
                ],
                detailRoute: ['route' => 'admin.system-settings.index'],
                permission: 'system-settings.manage',
                icon: 'bi-gear',
                message: 'System controls and infrastructure health from one platform section.',
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
