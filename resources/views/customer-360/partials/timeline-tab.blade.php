@include('customer-360.partials.timeline-section', [
    'viewModel' => $timeline,
    'businessTimeline' => $businessTimeline ?? false,
    'timelineQuery' => $timelineQuery ?? null,
    'loadMoreUrl' => $timelineLoadMoreUrl ?? null,
    'timelineRefreshUrl' => $timelineRefreshUrl ?? ($timelineLoadMoreUrl ?? null),
])
