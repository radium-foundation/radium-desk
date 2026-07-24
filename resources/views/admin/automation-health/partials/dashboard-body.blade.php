@props([
    'dashboard',
])

<div data-automation-health-embed>
    @include('admin.automation-health.partials.overview-cards', ['overview' => $dashboard['overview']])
    @include('admin.automation-health.partials.breakdown', ['breakdown' => $dashboard['breakdown']])
    @include('admin.automation-health.partials.filters', [
        'filterOptions' => $dashboard['filter_options'],
        'filters' => $dashboard['filters'],
    ])
    @include('admin.automation-health.partials.activity-table', ['activity' => $dashboard['activity']])
    @include('admin.automation-health.partials.failures', ['failures' => $dashboard['failures']])
</div>
