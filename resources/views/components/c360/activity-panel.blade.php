@props([
    'viewModel',
    'heading' => 'Activity',
    'showHeading' => true,
    'emptyMessage' => 'No activity recorded yet.',
    'loadMoreUrl' => null,
    'showLoadMore' => true,
    'showFilters' => true,
])

@php
    /** @var \App\Data\TimelineViewModel $viewModel */
    $filterEmptyMessages = [
        'all' => $emptyMessage,
        'system' => 'No system events',
        'customer' => 'No customer events',
        'support' => 'No support events',
        'notifications' => 'No notification events',
        'synchronization' => 'No synchronization events',
        'appointments' => 'No appointment events',
        'payments' => 'No payment events',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'c360-activity-panel']) }}
     data-c360-activity-panel
     data-unified-timeline>
    @if($showHeading)
        <div class="c360-activity-panel-header">
            <h3 class="c360-activity-panel-heading">{{ $heading }}</h3>
            <p class="c360-activity-panel-subtitle mb-0">Complete operational history in one chronological feed.</p>
        </div>
    @endif

    @if($showFilters)
        <div class="c360-activity-panel-filters unified-timeline-filters"
             role="toolbar"
             aria-label="Activity filters"
             data-timeline-filters>
            @foreach([
                'all' => 'All',
                'system' => 'System',
                'customer' => 'Customer',
                'support' => 'Support',
                'notifications' => 'Notifications',
                'synchronization' => 'Synchronization',
                'appointments' => 'Appointments',
                'payments' => 'Payments',
            ] as $filterKey => $filterLabel)
                <button type="button"
                        @class([
                            'unified-timeline-filter-chip',
                            'is-active' => $filterKey === 'all',
                        ])
                        data-timeline-filter-chip="{{ $filterKey }}"
                        aria-pressed="{{ $filterKey === 'all' ? 'true' : 'false' }}">
                    {{ $filterLabel }}
                </button>
            @endforeach
        </div>
        <div class="unified-timeline-filter-empty d-none"
             role="status"
             data-timeline-filter-empty
             hidden></div>
        <template data-timeline-filter-empty-messages>@json($filterEmptyMessages)</template>
    @endif

    @if($viewModel->isEmpty())
        <div class="c360-activity-panel-empty unified-timeline-empty"
             role="status"
             data-timeline-global-empty>
            <i class="bi bi-clock-history" aria-hidden="true"></i>
            <p class="mb-0">{{ $emptyMessage }}</p>
        </div>
    @else
        <div class="c360-activity-panel-feed unified-timeline" role="list" data-timeline-list>
            @foreach($viewModel->groups as $group)
                <section class="c360-activity-panel-group unified-timeline-group"
                         data-timeline-group="{{ $group->bucket->value }}">
                    <h4 class="c360-activity-panel-group-label unified-timeline-group-label">
                        {{ $group->label() }}
                    </h4>
                    <div class="c360-activity-panel-group-items unified-timeline-group-items" role="list">
                        @foreach($group->events as $event)
                            <x-c360.activity-item :event="$event" />
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        @if($showLoadMore && $viewModel->hasMore && $loadMoreUrl)
            <div class="c360-activity-panel-load-more unified-timeline-load-more-wrap"
                 data-timeline-load-more-wrap>
                <button type="button"
                        class="btn btn-sm btn-outline-secondary unified-timeline-load-more"
                        data-timeline-load-more
                        data-timeline-load-more-url="{{ $loadMoreUrl }}"
                        data-timeline-offset="{{ $viewModel->loadedCount }}"
                        data-timeline-total="{{ $viewModel->totalCount }}">
                    Load older events
                </button>
            </div>
        @endif
    @endif
</div>
