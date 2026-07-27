@props([
    'item',
])

@php
    /** @var \App\Data\Timeline\BusinessTimelineItem $item */
    use App\Support\AppDateFormatter;
@endphp

<article class="c360-business-timeline-item"
         role="listitem"
         data-timeline-event
         data-timeline-milestone
         data-timeline-milestone-id="{{ $item->id }}"
         data-timeline-filter="{{ implode(',', $item->filterTags) }}">
    <div class="c360-business-timeline-item-main">
        <div class="c360-business-timeline-item-icon" aria-hidden="true">
            <i class="bi {{ $item->type->icon() }}"></i>
        </div>
        <div class="c360-business-timeline-item-body">
            <div class="c360-business-timeline-item-header">
                <div class="c360-business-timeline-item-title-row">
                    <h5 class="c360-business-timeline-item-title">{{ $item->title }}</h5>
                    @if($item->isCluster && $item->rawCount > 1)
                        <span class="c360-business-timeline-item-count" aria-label="{{ $item->rawCount }} events">
                            {{ $item->rawCount }}
                        </span>
                    @endif
                </div>
                <time class="c360-business-timeline-item-time"
                      datetime="{{ $item->occurredAt->toIso8601String() }}"
                      title="{{ AppDateFormatter::timelineDatetime($item->occurredAt) }}">
                    {{ $item->timeLabel() }}
                </time>
            </div>
            @if(filled($item->summary))
                <p class="c360-business-timeline-item-summary">{{ $item->summary }}</p>
            @endif
            <details class="c360-business-timeline-item-raw" data-timeline-raw-details>
                <summary>Show Raw Events{{ $item->rawCount > 1 ? ' ('.$item->rawCount.')' : '' }}</summary>
                <div class="c360-business-timeline-item-raw-list" role="list">
                    @foreach($item->rawEvents as $event)
                        <x-c360.activity-item :event="$event" :nestedRaw="true" />
                    @endforeach
                </div>
            </details>
        </div>
    </div>
</article>
