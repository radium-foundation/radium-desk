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
         data-live-scope="{{ $dashboardLiveScope ?? 'operations_scope' }}"
         data-live-filter="{{ $serviceCaseFilter ?? $operationQueue }}"
         data-live-mode="{{ $dashboardLiveMode ?? 'auto' }}"
         data-live-interval="{{ $dashboardPollIntervalMs ?? 30000 }}"
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
         @if($reverbConfigured ?? false)
         data-reverb-key="{{ config('broadcasting.connections.reverb.key') }}"
         data-reverb-host="{{ config('broadcasting.connections.reverb.options.host') }}"
         data-reverb-port="{{ config('broadcasting.connections.reverb.options.port') }}"
         data-reverb-scheme="{{ config('broadcasting.connections.reverb.options.scheme') }}"
         @endif>
        <div class="dashboard-header d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-1 @if($usesAgentDashboard) dashboard-header--agent @endif mb-1">
            <div>
                <h1 class="@if($usesAgentDashboard) dashboard-header__title dashboard-header__title--agent @else h4 @endif mb-0">Dashboard</h1>
                @unless($usesAgentDashboard)
                    <p class="text-muted small mb-0">Welcome back, {{ auth()->user()->firstName() }}.</p>
                @endunless
            </div>
            <button type="button"
                    class="btn btn-sm btn-outline-primary agent-resume-customer d-none dashboard-u-focus-ring"
                    data-agent-resume-customer
                    hidden>
                Resume Last Customer
            </button>
        </div>

        <div id="dashboard-kpi-strip" class="dashboard-kpi-strip-host mb-1">
            @include('dashboard.partials.kpi-strip', ['stats' => $stats])
        </div>

        @if(auth()->user()?->can('incidents.view'))
            <div class="dashboard-primary-panel mb-1">
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
                ])
            </div>
        @endif

        @if(! $recentActivityStreams->isEmpty())
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

        @include('dashboard.partials.incoming-call-card-host')
        @include('dashboard.partials.customer-360-drawer-host', [
            'customer360TimelinePollIntervalMs' => $customer360TimelinePollIntervalMs ?? 30000,
            'customer360DeviceSyncPollIntervalMs' => $customer360DeviceSyncPollIntervalMs ?? 10000,
        ])
        @include('dashboard.partials.serial-number-modal')
    </div>
@endsection
