@php
    /** @var \App\Data\TimelineViewModel $viewModel */
@endphp

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

@if($viewModel->hasMore)
    <div class="c360-activity-panel-load-more unified-timeline-load-more-wrap" data-timeline-load-more-wrap>
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
    <div class="c360-activity-panel-load-more unified-timeline-load-more-wrap d-none"
         data-timeline-load-more-wrap
         hidden></div>
@endif
