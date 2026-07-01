@props([
    'viewModel',
    'heading' => 'Timeline',
    'showHeading' => true,
    'compact' => false,
    'emptyMessage' => 'No activity recorded yet.',
    'loadMoreUrl' => null,
    'showLoadMore' => true,
])

@php
    /** @var \App\Data\TimelineViewModel $viewModel */
@endphp

<div @class([
    'unified-timeline-wrap',
    'unified-timeline-wrap--compact' => $compact,
]) data-unified-timeline>
    @if($showHeading)
        <h3 @class([
            'unified-timeline-heading',
            'customer-360-section-title' => $compact,
            'order-workspace-section-title' => ! $compact,
        ])>{{ $heading }}</h3>
    @endif

    @if($viewModel->isEmpty())
        <div class="unified-timeline-empty" role="status">
            <i class="bi bi-clock-history" aria-hidden="true"></i>
            <p class="mb-0">{{ $emptyMessage }}</p>
        </div>
    @else
        <div class="unified-timeline" role="list" data-timeline-list>
            @foreach($viewModel->groups as $group)
                <section class="unified-timeline-group" data-timeline-group="{{ $group->bucket->value }}">
                    <h4 class="unified-timeline-group-label">{{ $group->label() }}</h4>
                    <div class="unified-timeline-group-items" role="list">
                        @foreach($group->events as $event)
                            <x-timeline-event :event="$event" />
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        @if($showLoadMore && $viewModel->hasMore && $loadMoreUrl)
            <div class="unified-timeline-load-more-wrap" data-timeline-load-more-wrap>
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
