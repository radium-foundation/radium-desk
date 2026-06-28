@props([
    'activityTimeline',
])

@include('orders.workspace.partials.timeline', [
    'activityTimeline' => $activityTimeline,
    'showHeading' => true,
    'heading' => 'Activity Timeline',
])
