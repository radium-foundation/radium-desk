@if(is_array($iraAdvisor ?? null))
    <x-c360.ira-advisor-card :viewModel="$iraAdvisor" class="mb-3" />
@endif
@if(is_array($customerHealthCard ?? null))
    <x-c360.health-card :viewModel="$customerHealthCard" class="mb-3" />
@endif
@if(is_array($customerInsights ?? null) && count($customerInsights) > 0)
    <x-c360.insights-card :insights="$customerInsights" class="mb-3" />
@endif
@include('customer-360.partials.timeline-section', [
    'viewModel' => $timeline,
    'loadMoreUrl' => $timelineLoadMoreUrl ?? null,
    'timelineRefreshUrl' => $timelineRefreshUrl ?? ($timelineLoadMoreUrl ?? null),
])
