@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        use App\Services\DashboardPersonalizationService;
        use App\Services\Operations\OperationsRoleService;

        $operationQueue = $operationQueue ?? DashboardPersonalizationService::QUEUE_ACTION_REQUIRED;
        $usesAgentDashboard = auth()->user() && app(OperationsRoleService::class)->usesSupportQueues(auth()->user());
    @endphp

    <div @class([
            'app-content-compact',
            'agent-dashboard' => $usesAgentDashboard,
        ])
         id="dashboard-page"
         data-workspace-context="dashboard"
         data-live-url="{{ route('dashboard.live') }}"
         data-live-rows-url="{{ route('dashboard.live.rows') }}"
         data-live-queue="{{ $operationQueue }}"
         data-live-workspace="{{ $operationsWorkspace ?? $operationQueue }}"
         data-live-scope="{{ $dashboardLiveScope ?? 'operations_scope' }}"
         data-live-filter="{{ $serviceCaseFilter ?? $operationQueue }}"
         data-operations-workspace-kind="{{ $operationsWorkspaceKind ?? 'case_queue' }}"
         data-operations-workspace-soft-switch="{{ ($operationsWorkspaceSoftSwitch ?? true) ? '1' : '0' }}"
         data-operations-workspace-phase2-embed="{{ ($operationsWorkspacePhase2Embed ?? true) ? '1' : '0' }}"
         data-operations-workspace-url="{{ route('dashboard.workspace') }}"
         data-live-mode="{{ $dashboardLiveMode ?? 'auto' }}"
         data-live-interval-active="{{ $dashboardPollIntervalActiveMs ?? 20000 }}"
         data-live-interval-idle="{{ $dashboardPollIntervalIdleMs ?? 60000 }}"
         data-live-interval="{{ $dashboardPollIntervalActiveMs ?? 20000 }}"
         data-live-updates-enabled="{{ ($dashboardLiveUpdatesEnabled ?? true) ? '1' : '0' }}"
         data-realtime-desktop-notifications="{{ ($desktopNotificationsEnabled ?? true) ? '1' : '0' }}"
         data-realtime-connection-indicator="{{ ($connectionStatusIndicatorEnabled ?? false) ? '1' : '0' }}"
         data-realtime-debug="{{ ($debugModeEnabled ?? false) ? '1' : '0' }}"
         data-realtime-lifecycle-debug="{{ ($debugModeEnabled ?? false) ? '1' : '0' }}"
         data-realtime-provider="{{ $realtimeProvider ?? 'polling' }}"
         data-realtime-status-url="{{ $realtimeStatusUrl ?? '' }}"
         data-realtime-force-reconnect-at="{{ $realtimeForceReconnectAt ?? '' }}"
         data-agent-reminder-interval-seconds="{{ $agentReminderIntervalSeconds ?? 60 }}"
         data-user-id="{{ auth()->id() }}"
         data-dashboard-search-rows-url="{{ route('dashboard.service-cases.search-rows') }}"
         data-dashboard-load-more-url="{{ route('dashboard.service-cases.load-more') }}"
         data-open-customer-360-incident-id="{{ $openCustomer360IncidentId ?? '' }}"
         data-open-customer-360-reference="{{ $openCustomer360Reference ?? '' }}"
         data-open-customer-360-more-menu="{{ ($openCustomer360MoreMenu ?? false) ? '1' : '' }}"
         data-customer-360-url="{{ url('dashboard/service-cases') }}"
         @if(isset($stats['next_appointment']) && is_array($stats['next_appointment']))
         data-next-appointment='@json($stats['next_appointment'])'
         @endif
         @if($echoConfigured ?? false)
         data-echo-broadcaster="{{ $echoBroadcaster }}"
         data-echo-key="{{ $echoKey }}"
         data-echo-host="{{ $echoHost }}"
         data-echo-port="{{ $echoPort }}"
         data-echo-scheme="{{ $echoScheme }}"
         @endif>
        <div class="dashboard-header d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-1 @if($usesAgentDashboard) dashboard-header--agent @endif mb-1">
            <div>
                <h1 class="@if($usesAgentDashboard) dashboard-header__title dashboard-header__title--agent @else h4 @endif mb-0">Dashboard</h1>
                @unless($usesAgentDashboard)
                    <p class="text-muted small mb-0">Welcome back, {{ auth()->user()->firstName() }}.</p>
                @endunless
            </div>
            <div class="dashboard-recent-customers d-none"
                 data-agent-recent-customers
                 data-operations-widget="recent-customers"
                 aria-label="Recent Customers"
                 hidden>
                <span class="dashboard-recent-customers__label">Recent Customers</span>
                <div class="dashboard-recent-customers__chips"
                     data-agent-recent-customers-list></div>
            </div>
        </div>

        <div id="dashboard-kpi-strip" class="dashboard-kpi-strip-host mb-1">
            @include('dashboard.partials.kpi-strip', ['stats' => $stats])
        </div>

        @if(auth()->user()?->can('incidents.view') || (($operationsWorkspacePhase2Embed ?? true) && auth()->user()?->can('refunds.view')))
            <div class="dashboard-primary-panel mb-1" data-operations-primary-panel>
                <div data-operations-case-host
                     @if(($operationsWorkspaceKind ?? 'case_queue') === 'embedded') hidden @endif>
                    @if(auth()->user()?->can('incidents.view'))
                        @include('dashboard.partials.recent-service-cases', [
                            'recentServiceCases' => $recentServiceCases,
                            'serviceCaseFilter' => $serviceCaseFilter ?? $operationQueue,
                            'operationQueue' => $operationQueue,
                            'operationQueues' => $operationQueues ?? [],
                            'availableOperationQueues' => $availableOperationQueues ?? [],
                            'showsQueueNavigation' => $showsQueueNavigation ?? true,
                            'serviceCasePanelTitle' => $serviceCasePanelTitle ?? 'Service Cases',
                            'serviceCaseFilterCounts' => $serviceCaseFilterCounts ?? [],
                            'serviceCaseTotalCount' => $serviceCaseTotalCount ?? 0,
                            'serviceCaseHasMore' => $serviceCaseHasMore ?? false,
                            'canManageTransactions' => $canManageTransactions ?? false,
                            'compactAgentLayout' => $usesAgentDashboard,
                            'emailIntakeWidget' => $stats['email_intake_widget'] ?? null,
                        ])
                    @endif
                </div>
                <div data-operations-embedded-host
                     @if(($operationsWorkspaceKind ?? 'case_queue') !== 'embedded') hidden @endif>
                    @if(($operationsWorkspaceKind ?? 'case_queue') === 'embedded')
                        {!! $embeddedWorkspacePanelHtml !!}
                    @endif
                </div>
            </div>
        @endif

        @if(($teamActivityEnabled ?? true) && ! ($teamActivityPanel?->empty ?? true))
            @include('dashboard.partials.team-activity-panel', ['panel' => $teamActivityPanel])
        @elseif(! ($teamActivityEnabled ?? true) && ! $recentActivityStreams->isEmpty())
            @include('dashboard.partials.recent-activity-feed', ['streams' => $recentActivityStreams])
        @endif

        @if($canQuickCreate)
            @include('dashboard.partials.quick-create-form', [
                'enabledProducts' => $enabledProducts ?? [],
                'enabledSources' => $enabledSources ?? [],
            ])
            @include('dashboard.partials.legacy-search-confirm-modal', [
                'enabledSources' => $enabledSources ?? [],
            ])
        @endif

        @include('dashboard.partials.customer-360-drawer-host', [
            'customer360TimelinePollIntervalMs' => $customer360TimelinePollIntervalMs ?? 30000,
            'customer360DeviceSyncPollIntervalMs' => $customer360DeviceSyncPollIntervalMs ?? 10000,
        ])
        @include('dashboard.partials.serial-number-modal')
    </div>
@endsection

@push('vite')
    @vite('resources/js/pages/dashboard.js')
@endpush
