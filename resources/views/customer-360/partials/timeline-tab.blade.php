@include('customer-360.partials.timeline-section', [
    'viewModel' => $timeline,
    'loadMoreUrl' => $timelineLoadMoreUrl ?? null,
    'timelineRefreshUrl' => $timelineRefreshUrl ?? ($timelineLoadMoreUrl ?? null),
])
