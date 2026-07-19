@props([
    'thread',
])

@php
    $latest = $thread->latest();
@endphp

@if($latest)
    <li class="dashboard-activity-thread @if($thread->isCollapsible()) dashboard-activity-thread--collapsible @endif"
        @if($thread->isCollapsible()) data-activity-thread @endif
        @if($thread->incidentId) data-activity-thread-incident="{{ $thread->incidentId }}" @endif
        role="listitem">
        <x-dashboard.recent-activity-row
            :item="$latest"
            :show-incident="true"
            :thread-count="$thread->isCollapsible() ? $thread->count() : null" />

        @if($thread->isCollapsible())
            <button type="button"
                    class="dashboard-activity-thread-toggle"
                    data-activity-thread-toggle
                    aria-expanded="false">
                <span class="dashboard-activity-thread-toggle-icon" aria-hidden="true"></span>
                <span data-activity-thread-toggle-label>History</span>
            </button>

            <div class="dashboard-activity-thread-history" data-activity-thread-history hidden>
                @foreach(array_slice($thread->items, 1) as $item)
                    <x-dashboard.recent-activity-row :item="$item" :show-incident="false" />
                @endforeach
            </div>
        @endif
    </li>
@endif
