@include('customer-360.partials.operations-health', ['health' => $operationsHealth ?? []])
@include('customer-360.partials.sla-metrics', ['slaMetrics' => $slaMetrics ?? null])
@include('customer-360.partials.timeline-section', [
    'viewModel' => $timeline,
    'loadMoreUrl' => $timelineLoadMoreUrl ?? null,
    'timelineRefreshUrl' => $timelineRefreshUrl ?? ($timelineLoadMoreUrl ?? null),
])
