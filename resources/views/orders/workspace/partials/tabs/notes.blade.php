@props([
    'order',
    'timelineRemarks',
])

@include('remarks.partials.panel', [
    'remarkable' => $order,
    'timelineRemarks' => $timelineRemarks,
    'showContextBadge' => true,
])
