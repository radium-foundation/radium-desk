<?php

namespace App\Providers;

use App\Contracts\AI\AIProvider;
use App\Contracts\Customer360\CaseIntelligenceLanguageEnhancer;
use App\Contracts\Operations\IraReasoningProvider;
use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Contracts\Workforce\CalendarPolicy;
use App\Contracts\Workforce\ContributionPolicy;
use App\Contracts\Workforce\ExtraQualificationPolicy;
use App\Contracts\Workforce\IncentivePolicy;
use App\Contracts\Workforce\WorkforceEventPublisher;
use App\Enums\ContributionSignalId;
use App\Events\Finance\OrderPaid;
use App\Events\Finance\RefundCompleted;
use App\Events\Inventory\InventorySaleCompleted;
use App\Events\Operations\SupportAppointmentSmartAssigned;
use App\Listeners\BroadcastNotificationCreated;
use App\Listeners\Finance\PostOrderPaidJournal;
use App\Listeners\Finance\PostPosSaleJournal;
use App\Listeners\Finance\PostRefundCompletedJournal;
use App\Listeners\LogScheduledTaskTiming;
use App\Listeners\Operations\DispatchIraSmartAssignmentNotification;
use App\Models\DeviceModel;
use App\Models\IraMemory;
use App\Models\Order;
use App\Models\SettingProduct;
use App\Models\SettingSource;
use App\Models\SystemSetting;
use App\Models\User;
use App\Policies\DashboardPolicy;
use App\Policies\IraMemoryPolicy;
use App\Policies\SettingPolicy;
use App\Policies\SystemSettingPolicy;
use App\Policies\TeamActivityPolicy;
use App\Policies\Workforce360Policy;
use App\Services\AI\Providers\NullAIProvider;
use App\Services\AssignReferenceBatchCoalescer;
use App\Services\Automation\AutomationIdempotencyKeyGenerator;
use App\Services\Automation\AutomationRuntime;
use App\Services\Automation\Handlers\AutoCloseActionHandler;
use App\Services\Automation\Handlers\NotificationActionHandler;
use App\Services\Automation\Handlers\NotifyTeamActionHandler;
use App\Services\Bonvoice\BonvoiceIncomingCallLatency;
use App\Services\ChangelogService;
use App\Services\CommunicationActions\CommunicationActionAvailabilityService;
use App\Services\CommunicationActions\CommunicationActionEligibilityService;
use App\Services\CommunicationActions\CommunicationActionRegistry;
use App\Services\CommunicationActions\CommunicationActionTargetProviderRegistry;
use App\Services\CommunicationActions\Targets\DeviceModelDriverTargetProvider;
use App\Services\CommunicationActions\Targets\DeviceModelProductTargetProvider;
use App\Services\CommunicationActions\Targets\DeviceModelRdServiceTargetProvider;
use App\Services\CommunicationActions\Targets\ReviewPlatformTargetProvider;
use App\Services\Customer360\Intelligence\CaseIntelligenceEngine;
use App\Services\Customer360\Intelligence\NullCaseIntelligenceLanguageEnhancer;
use App\Services\Dashboard\DashboardClassificationIndex;
use App\Services\Dashboard\DashboardIncidentQueueMembership;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\DashboardBroadcastService;
use App\Services\GlobalSearch\ServiceCaseGlobalSearchProvider;
use App\Services\GlobalSearchService;
use App\Services\Interakt\InteraktTemplateConfigurationValidator;
use App\Services\MissingSerial\MissingSerialAutomationService;
use App\Services\Notifications\Channels\DesktopChannel;
use App\Services\Notifications\Channels\EmailChannel;
use App\Services\Notifications\Channels\TelegramChannel;
use App\Services\Notifications\Channels\WhatsAppChannel;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Operations\OpenAIReasoningProvider;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\RuleBasedReasoningProvider;
use App\Services\Operations\TeamAvailabilityOverviewService;
use App\Services\Operations\TeamPerformanceMetricsService;
use App\Services\Operations\WorkCalendarService;
use App\Services\Operations\WorkforceAuthorityService;
use App\Services\Operations\WorkingHoursTodayService;
use App\Services\Performance\PerformanceRuntimeConfig;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\RadiumBox\RadiumBoxRequestCache;
use App\Services\ServiceCaseAutomationStatusService;
use App\Services\SettingService;
use App\Services\StatutoryInvoice\NullEInvoiceGateway;
use App\Services\SupportContactConfiguration;
use App\Services\SupportContactResolver;
use App\Services\SystemSettingsAdminCollection;
use App\Services\SystemSettingsService;
use App\Services\Timeline\Customer360TimelineRequestCache;
use App\Services\Timeline\Customer360TimelineSourceRegistry;
use App\Services\Timeline\Factories\ClassTimelineSourceFactory;
use App\Services\Timeline\Factories\OrderCustomerTimelineSourceFactory;
use App\Services\Timeline\Sources\AppointmentTimelineEventSource;
use App\Services\Timeline\Sources\BonVoiceCallTimelineEventSource;
use App\Services\Timeline\Sources\CorrectSerialRequestTimelineEventSource;
use App\Services\Timeline\Sources\CustomerDataCorrectionTimelineEventSource;
use App\Services\Timeline\Sources\CustomerIdentityProtectionTimelineEventSource;
use App\Services\Timeline\Sources\CustomerWaitingLifecycleTimelineEventSource;
use App\Services\Timeline\Sources\IncomingEmailTimelineEventSource;
use App\Services\Timeline\Sources\NotificationTimelineEventSource;
use App\Services\Timeline\Sources\OutgoingEmailTimelineEventSource;
use App\Services\Timeline\Sources\RadiumBoxSyncTimelineEventSource;
use App\Services\Timeline\Sources\ServiceCaseLifecycleTimelineEventSource;
use App\Services\Timeline\Sources\WhatsAppTemplateDispatchTimelineSource;
use App\Services\Timeline\Sources\WhatsAppTimelineEventSource;
use App\Services\VersionService;
use App\Services\Workforce\Contribution\ConfigContributionPolicy;
use App\Services\Workforce\Contribution\ContributionEngine;
use App\Services\Workforce\Contribution\Signals\ActiveDurationSignalCollector;
use App\Services\Workforce\Contribution\Signals\CallSignalCollector;
use App\Services\Workforce\Contribution\Signals\CaseClosedSignalCollector;
use App\Services\Workforce\Contribution\Signals\CaseSignalCollector;
use App\Services\Workforce\Contribution\Signals\CasesResolvedSignalCollector;
use App\Services\Workforce\Contribution\Signals\CommunicationsSignalCollector;
use App\Services\Workforce\Contribution\Signals\EmailSignalCollector;
use App\Services\Workforce\Contribution\Signals\OrderSignalCollector;
use App\Services\Workforce\Contribution\Signals\RemarkSignalCollector;
use App\Services\Workforce\Contribution\Signals\ReservedSignalCollector;
use App\Services\Workforce\Contribution\Signals\StatusUpdateSignalCollector;
use App\Services\Workforce\Contribution\Signals\WhatsAppSignalCollector;
use App\Services\Workforce\DailyWorkforceEngine;
use App\Services\Workforce\Events\NullWorkforceEventPublisher;
use App\Services\Workforce\Events\SafeWorkforceEventPublisher;
use App\Services\Workforce\Extra\ExtraQualificationEngine;
use App\Services\Workforce\Extra\RuleBookExtraQualificationPolicy;
use App\Services\Workforce\PayrollMonthLockService;
use App\Services\Workforce\Policies\CalendarPolicyAdapter;
use App\Services\Workforce\Recognition\ConfigIncentivePolicy;
use App\Support\Administration\PerformanceIntelligenceAccess;
use App\Support\Administration\PlatformConfigurationAccess;
use App\Support\Dashboard\Contracts\DashboardAttentionScoreCalculator;
use App\Support\Dashboard\NullDashboardAttentionScoreCalculator;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
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
        $this->app->singleton(EInvoiceGateway::class, NullEInvoiceGateway::class);
        $this->app->singleton(RadiumBoxRequestCache::class);
        $this->app->singleton(BonvoiceIncomingCallLatency::class);
        $this->app->singleton(Customer360TimelineRequestCache::class);
        $this->app->singleton(Customer360TimelineSourceRegistry::class, function ($app): Customer360TimelineSourceRegistry {
            return new Customer360TimelineSourceRegistry([
                $app->make(OrderCustomerTimelineSourceFactory::class),
                new ClassTimelineSourceFactory($app, WhatsAppTimelineEventSource::class),
                new ClassTimelineSourceFactory($app, WhatsAppTemplateDispatchTimelineSource::class),
                new ClassTimelineSourceFactory($app, NotificationTimelineEventSource::class),
                new ClassTimelineSourceFactory($app, CorrectSerialRequestTimelineEventSource::class),
                new ClassTimelineSourceFactory($app, RadiumBoxSyncTimelineEventSource::class),
                new ClassTimelineSourceFactory($app, AppointmentTimelineEventSource::class),
                new ClassTimelineSourceFactory($app, ServiceCaseLifecycleTimelineEventSource::class),
                new ClassTimelineSourceFactory($app, BonVoiceCallTimelineEventSource::class),
                new ClassTimelineSourceFactory($app, IncomingEmailTimelineEventSource::class),
                new ClassTimelineSourceFactory($app, OutgoingEmailTimelineEventSource::class),
                new ClassTimelineSourceFactory($app, CustomerDataCorrectionTimelineEventSource::class),
                new ClassTimelineSourceFactory($app, CustomerIdentityProtectionTimelineEventSource::class),
                new ClassTimelineSourceFactory($app, CustomerWaitingLifecycleTimelineEventSource::class),
            ]);
        });
        $this->app->scoped(DashboardSnapshotStore::class);
        $this->app->scoped(DashboardClassificationIndex::class);
        $this->app->scoped(DashboardIncidentQueueMembership::class);
        $this->app->scoped(SettingService::class);
        $this->app->scoped(SystemSettingsAdminCollection::class);
        $this->app->scoped(AssignReferenceBatchCoalescer::class);
        $this->app->scoped(DashboardBroadcastService::class);
        $this->app->scoped(OperationsQueueClassifier::class);
        $this->app->scoped(ServiceCaseAutomationStatusService::class);
        $this->app->scoped(RadiumBoxOrderEnrichmentSyncStore::class);
        $this->app->scoped(TeamAvailabilityOverviewService::class);
        $this->app->scoped(TeamPerformanceMetricsService::class);
        $this->app->scoped(WorkforceAuthorityService::class);
        $this->app->scoped(WorkCalendarService::class);
        $this->app->scoped(WorkingHoursTodayService::class);
        $this->app->scoped(PresenceEngineService::class);
        $this->app->scoped(PayrollMonthLockService::class);
        $this->app->scoped(
            CalendarPolicy::class,
            CalendarPolicyAdapter::class,
        );
        $this->app->scoped(DailyWorkforceEngine::class);
        $this->app->singleton(
            WorkforceEventPublisher::class,
            function ($app): WorkforceEventPublisher {
                $inner = $app->bound('workforce.events.inner_publisher')
                    ? $app->make('workforce.events.inner_publisher')
                    : $app->make(NullWorkforceEventPublisher::class);

                return new SafeWorkforceEventPublisher($inner);
            },
        );
        $this->app->singleton(
            ContributionPolicy::class,
            ConfigContributionPolicy::class,
        );
        $this->app->singleton(ContributionEngine::class, function ($app): ContributionEngine {
            return new ContributionEngine(
                contributionPolicy: $app->make(ContributionPolicy::class),
                workforceEventPublisher: $app->make(WorkforceEventPublisher::class),
                collectors: [
                    $app->make(ActiveDurationSignalCollector::class),
                    $app->make(CaseSignalCollector::class),
                    $app->make(CasesResolvedSignalCollector::class),
                    $app->make(CaseClosedSignalCollector::class),
                    $app->make(CommunicationsSignalCollector::class),
                    $app->make(EmailSignalCollector::class),
                    $app->make(WhatsAppSignalCollector::class),
                    $app->make(CallSignalCollector::class),
                    $app->make(StatusUpdateSignalCollector::class),
                    $app->make(RemarkSignalCollector::class),
                    $app->make(OrderSignalCollector::class),
                    new ReservedSignalCollector(ContributionSignalId::Sales),
                    new ReservedSignalCollector(ContributionSignalId::ManualAdjustment),
                ],
            );
        });
        $this->app->singleton(
            ExtraQualificationPolicy::class,
            RuleBookExtraQualificationPolicy::class,
        );
        $this->app->singleton(ExtraQualificationEngine::class);
        $this->app->singleton(
            IncentivePolicy::class,
            ConfigIncentivePolicy::class,
        );
        $this->app->bind(
            DashboardAttentionScoreCalculator::class,
            NullDashboardAttentionScoreCalculator::class,
        );

        $this->app->singleton(IraReasoningProvider::class, function ($app): IraReasoningProvider {
            return match (config('ira.reasoning_provider')) {
                'openai' => $app->make(OpenAIReasoningProvider::class),
                default => $app->make(RuleBasedReasoningProvider::class),
            };
        });

        $this->app->singleton(AIProvider::class, function ($app): AIProvider {
            return match (config('ai.provider')) {
                'null' => $app->make(NullAIProvider::class),
                default => $app->make(NullAIProvider::class),
            };
        });

        $this->app->singleton(CaseIntelligenceLanguageEnhancer::class, NullCaseIntelligenceLanguageEnhancer::class);
        $this->app->scoped(CaseIntelligenceEngine::class);

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
                    $app->make(AutoCloseActionHandler::class),
                    $app->make(NotifyTeamActionHandler::class),
                ],
            );
        });

        $this->app->singleton(CommunicationActionTargetProviderRegistry::class, function ($app): CommunicationActionTargetProviderRegistry {
            return new CommunicationActionTargetProviderRegistry(
                providers: [
                    $app->make(DeviceModelDriverTargetProvider::class),
                    $app->make(ReviewPlatformTargetProvider::class),
                    $app->make(DeviceModelRdServiceTargetProvider::class),
                    $app->make(DeviceModelProductTargetProvider::class),
                ],
                communicationActionRegistry: $app->make(CommunicationActionRegistry::class),
                eligibilityService: $app->make(CommunicationActionEligibilityService::class),
                availabilityService: $app->make(CommunicationActionAvailabilityService::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->applyApplicationTimezone();
        $this->applySupportContactConfiguration();
        $this->validateInteraktTemplateConfiguration();

        Event::listen(NotificationSent::class, BroadcastNotificationCreated::class);
        // Ira operational Telegram (assignments, risks, briefings) is owned by
        // DispatchIraSmartAssignmentNotification + IraCommunicationService.
        // The legacy DispatchSupportAssignmentTelegramNotification listener was removed
        // so it cannot be re-registered via event discovery.
        Event::listen(SupportAppointmentSmartAssigned::class, DispatchIraSmartAssignmentNotification::class);
        Event::listen(OrderPaid::class, PostOrderPaidJournal::class);
        Event::listen(RefundCompleted::class, PostRefundCompletedJournal::class);
        Event::listen(InventorySaleCompleted::class, PostPosSaleJournal::class);
        Event::listen([
            ScheduledTaskStarting::class,
            ScheduledTaskFinished::class,
            ScheduledTaskSkipped::class,
            ScheduledTaskFailed::class,
            ScheduledBackgroundTaskFinished::class,
        ], LogScheduledTaskTiming::class);

        Order::updated(function (Order $order): void {
            if ($order->wasChanged('serial_number') && $order->isSerialLocked()) {
                app(MissingSerialAutomationService::class)->markCompletedIfApplicable(
                    $order->fresh(),
                    'serial_resolved',
                );
            }
        });

        Gate::define('viewDashboardHardware', fn (User $user): bool => app(DashboardPolicy::class)->viewHardware($user));
        Gate::define('teamActivity.view', fn (User $user): bool => app(TeamActivityPolicy::class)->view($user));
        Gate::define(
            'managePlatformConfiguration',
            fn (?User $user): bool => PlatformConfigurationAccess::canManage($user),
        );
        Gate::define(
            'viewPerformanceIntelligence',
            fn (?User $user): bool => PerformanceIntelligenceAccess::canView($user),
        );

        $workforce360Policy = Workforce360Policy::class;
        Gate::define('workforce360.viewTeam', fn (User $user): bool => app($workforce360Policy)->viewTeam($user));
        Gate::define('workforce360.viewMember', fn (User $user, User $member): bool => app($workforce360Policy)->viewMember($user, $member));
        Gate::define('workforce360.viewSelf', fn (User $user): bool => app($workforce360Policy)->viewSelf($user));

        Gate::policy(SettingProduct::class, SettingPolicy::class);
        Gate::policy(SettingSource::class, SettingPolicy::class);
        Gate::policy(DeviceModel::class, SettingPolicy::class);
        Gate::policy(SystemSetting::class, SystemSettingPolicy::class);
        Gate::policy(IraMemory::class, IraMemoryPolicy::class);

        Paginator::useBootstrapFive();

        View::composer(['layouts.app', 'layouts.partials.navbar'], function ($view): void {
            $view->with('performanceRuntime', app(PerformanceRuntimeConfig::class)->forBlade());
        });

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

        View::composer([
            'layouts.partials.version-footer',
            'layouts.partials.whats-new-modal',
        ], function ($view): void {
            $versionService = app(VersionService::class);

            $view->with([
                'applicationLabel' => $versionService->applicationLabel(),
                'shortVersionLabel' => $versionService->shortVersionLabel(),
                'buildLabel' => $versionService->buildLabel(),
                'footerTitle' => $versionService->footerTitle(),
            ]);
        });

        View::composer('layouts.partials.whats-new-modal', function ($view): void {
            $changelogService = app(ChangelogService::class);

            $view->with([
                'changelogEntries' => $changelogService->currentReleaseEntries(),
                'missingReleaseNotesMessage' => $changelogService->missingReleaseNotesMessage(),
            ]);
        });

        View::composer('emails.layouts.master', function ($view): void {
            $view->with(app(SupportContactResolver::class)->mergeIntoVariables($view->getData()));
        });
    }

    private function validateInteraktTemplateConfiguration(): void
    {
        $this->app->booted(function (): void {
            try {
                $this->app->make(InteraktTemplateConfigurationValidator::class)->logValidationSummaryOnce();
            } catch (\Throwable) {
                //
            }
        });
    }

    private function applySupportContactConfiguration(): void
    {
        $this->app->booted(function (): void {
            try {
                $this->app->make(SupportContactConfiguration::class)->applyToConfig();
            } catch (\Throwable) {
                //
            }
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
