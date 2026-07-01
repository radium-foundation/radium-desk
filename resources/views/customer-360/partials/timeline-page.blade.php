@php
    /** @var \App\Data\TimelineViewModel $viewModel */
@endphp

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

@if($viewModel->hasMore)
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
@else
    <div class="unified-timeline-load-more-wrap d-none" data-timeline-load-more-wrap hidden></div>
@endif
