@php
    $dashboardModules = config('ui.dashboard.modules', []);
    $requestedView = request()->query('view', 'all');
    $legacyHardwareViews = ['warehouse', 'dispatch'];
    $activeView = match (true) {
        in_array($requestedView, $legacyHardwareViews, true) => 'hardware_orders',
        array_key_exists($requestedView, $dashboardModules) => $requestedView,
        default => 'all',
    };
    $currentFilter = request()->query('filter');

    $moduleUrl = function (string $view) use ($currentFilter): string {
        $params = [];

        if ($view !== 'all') {
            $params['view'] = $view;
        }

        if (in_array($view, ['all', 'service_cases'], true) && filled($currentFilter)) {
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
        @endforeach
    </div>
</nav>
