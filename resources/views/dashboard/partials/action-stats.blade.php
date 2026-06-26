@props([
    'stats',
])

@include('dashboard.partials.kpi-strip', ['stats' => $stats])
