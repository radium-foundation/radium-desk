@php
    use App\Services\DashboardPersonalizationService;

    $dashboardModules = $dashboardModules ?? config('ui.dashboard.modules', []);
    $activeView = $dashboardView ?? DashboardPersonalizationService::VIEW_ALL;
    $currentFilter = request()->query('filter');
    $personalization = app(DashboardPersonalizationService::class);
    $defaultView = $personalization->defaultViewFor(auth()->user());

    $moduleUrl = function (string $view) use ($currentFilter, $defaultView): string {
        $params = [];

        if ($view !== $defaultView) {
            $params['view'] = $view;
        }

        if (in_array($view, [
            DashboardPersonalizationService::VIEW_ALL,
            DashboardPersonalizationService::VIEW_TEAM,
            DashboardPersonalizationService::VIEW_MY_WORK,
        ], true) && filled($currentFilter)) {
            $params['filter'] = $currentFilter;
        }

        return route('dashboard', $params);
    };
@endphp

<nav class="dashboard-module-nav mb-1"
     aria-label="Dashboard modules">
    <div class="dashboard-module-nav__list"
         role="tablist">
        @foreach($dashboardModules as $moduleKey => $moduleMeta)
            @if($moduleKey === DashboardPersonalizationService::VIEW_HARDWARE_ORDERS)
                @can('viewDashboardHardware')
                    <a href="{{ $moduleUrl($moduleKey) }}"
                       @class([
                           'dashboard-module-nav__tab',
                           'is-active' => $activeView === $moduleKey,
                       ])
                       role="tab"
                       @if($activeView === $moduleKey) aria-selected="true" @else aria-selected="false" @endif>
                        <i class="bi {{ $moduleMeta['icon'] }} dashboard-module-nav__icon" aria-hidden="true"></i>
                        <span>{{ $moduleMeta['label'] }}</span>
                    </a>
                @endcan
            @else
                <a href="{{ $moduleUrl($moduleKey) }}"
                   @class([
                       'dashboard-module-nav__tab',
                       'is-active' => $activeView === $moduleKey,
                   ])
                   role="tab"
                   @if($activeView === $moduleKey) aria-selected="true" @else aria-selected="false" @endif>
                    <i class="bi {{ $moduleMeta['icon'] }} dashboard-module-nav__icon" aria-hidden="true"></i>
                    <span>{{ $moduleMeta['label'] }}</span>
                </a>
            @endif
        @endforeach
    </div>
</nav>
