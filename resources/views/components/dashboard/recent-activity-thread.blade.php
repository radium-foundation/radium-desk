@props([
    'thread',
])

@php
    $latest = $thread->latest();
    $isCollapsible = $thread->isCollapsible();
@endphp

@if($latest)
    <li @class([
            'dashboard-activity-thread',
            'dashboard-activity-thread--collapsible' => $isCollapsible,
        ])
        @if($isCollapsible) data-activity-thread @endif
        @if($thread->incidentId) data-activity-thread-incident="{{ $thread->incidentId }}" @endif
        role="listitem">
        <x-dashboard.recent-activity-row
            :item="$latest"
            :show-incident="true" />

        @if($isCollapsible)
            <button type="button"
                    class="dashboard-activity-thread-toggle"
                    data-activity-thread-toggle
                    aria-expanded="false"
                    aria-label="Show history"
                    title="History"></button>

            <template data-activity-thread-history-source>
                @foreach(array_slice($thread->items, 1) as $item)
                    <x-dashboard.recent-activity-row :item="$item" :show-incident="false" />
                @endforeach
            </template>
            <div class="dashboard-activity-thread-history" data-activity-thread-history hidden></div>
        @endif
    </li>
@endif
