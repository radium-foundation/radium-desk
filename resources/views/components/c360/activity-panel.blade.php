@props([
    'viewModel',
    'heading' => 'Activity',
    'showHeading' => true,
    'emptyMessage' => 'No activity recorded yet.',
    'loadMoreUrl' => null,
    'showLoadMore' => true,
    'showFilters' => true,
    'businessTimeline' => false,
    'timelineQuery' => null,
])

@php
    $isBusiness = (bool) $businessTimeline;
    $activeQuery = filled($timelineQuery) ? (string) $timelineQuery : null;
    if ($activeQuery === null && $isBusiness && isset($viewModel->query)) {
        $activeQuery = $viewModel->query;
    }
    $searchEmptyDescription = filled($activeQuery)
        ? 'No timeline matches for “'.$activeQuery.'”.'
        : null;
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

<div {{ $attributes->merge(['class' => 'c360-activity-panel'.($isBusiness ? ' c360-activity-panel--business' : '')]) }}
     data-c360-activity-panel
     data-unified-timeline
     @if($isBusiness) data-business-timeline @endif
     @if($loadMoreUrl) data-timeline-base-url="{{ $loadMoreUrl }}" @endif>

    @if($showHeading)
        <div class="c360-activity-panel-header">
            <h3 class="c360-activity-panel-heading">{{ $heading }}</h3>
            <p class="c360-activity-panel-subtitle mb-0">
                {{ $isBusiness ? 'Business milestones in chronological order. Expand any row for raw events.' : 'Complete operational history in one chronological feed.' }}
            </p>
        </div>
    @endif

    @if($isBusiness)
        <div class="c360-activity-panel-search">
            <label class="visually-hidden" for="c360-timeline-search">Search timeline</label>
            <input type="search"
                   id="c360-timeline-search"
                   class="form-control form-control-sm c360-timeline-search-input"
                   placeholder="Search timeline…"
                   value="{{ $activeQuery }}"
                   data-timeline-search
                   autocomplete="off">
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
        <div class="c360-empty-state c360-empty-state--compact unified-timeline-filter-empty d-none"
             role="status"
             data-timeline-filter-empty
             hidden>
            <div class="c360-empty-state-icon" aria-hidden="true">
                <i class="bi bi-funnel"></i>
            </div>
            <p class="c360-empty-state-title mb-0" data-c360-empty-message></p>
        </div>
        <template data-timeline-filter-empty-messages>@json($filterEmptyMessages)</template>
    @endif

    @if($viewModel->isEmpty())
        @if(filled($activeQuery))
            <x-c360.empty-state
                icon="bi-search"
                title="No timeline matches"
                :description="$searchEmptyDescription"
                class="c360-activity-panel-empty unified-timeline-empty"
                data-timeline-global-empty
            />
        @else
            <x-c360.empty-state
                icon="bi-clock-history"
                title="No activity yet"
                description="Customer interactions, system events, and support actions will appear here as they happen."
                action-label="View all filters"
                data-c360-empty-focus-timeline-filters
                class="c360-activity-panel-empty unified-timeline-empty"
                data-timeline-global-empty
            />
        @endif
    @else
        <div class="c360-activity-panel-feed unified-timeline" role="list" data-timeline-list>
            @foreach($viewModel->groups as $group)
                <section class="c360-activity-panel-group unified-timeline-group"
                         data-timeline-group="{{ $group->bucket->value }}">
                    <h4 class="c360-activity-panel-group-label unified-timeline-group-label">
                        {{ $group->label() }}
                    </h4>
                    <div class="c360-activity-panel-group-items unified-timeline-group-items" role="list">
                        @if($isBusiness)
                            @foreach($group->items as $item)
                                <x-c360.business-timeline-item :item="$item" />
                            @endforeach
                        @else
                            @foreach($group->events as $event)
                                <x-c360.activity-item :event="$event" />
                            @endforeach
                        @endif
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
                        data-timeline-total="{{ $viewModel->totalCount }}"
                        @if(filled($activeQuery)) data-timeline-query="{{ $activeQuery }}" @endif>
                    {{ $isBusiness ? 'Load older milestones' : 'Load older events' }}
                </button>
            </div>
        @endif
    @endif
</div>
