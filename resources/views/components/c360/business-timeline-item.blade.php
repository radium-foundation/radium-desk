@props([
    'item',
])

@php
    /** @var \App\Data\Timeline\BusinessTimelineItem $item */
    use App\Support\AppDateFormatter;

    $iconClass = $item->iconClass ?? $item->type->icon();
@endphp

<article class="c360-business-timeline-item"
         role="listitem"
         data-timeline-event
         data-timeline-milestone
         data-timeline-milestone-id="{{ $item->id }}"
         @if($item->unifiedPresentation) data-timeline-unified-email-reopen @endif
         data-timeline-filter="{{ implode(',', $item->filterTags) }}">
    <div class="c360-business-timeline-item-main">
        <div class="c360-business-timeline-item-icon" aria-hidden="true">
            <i class="bi {{ $iconClass }}"></i>
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

            @if($item->actionBadges !== [])
                <ul class="c360-business-timeline-item-badges" aria-label="Actions taken">
                    @foreach($item->actionBadges as $badge)
                        <li class="c360-business-timeline-item-badge">✓ {{ $badge }}</li>
                    @endforeach
                </ul>
            @endif

            @if($item->displayFields !== [])
                <dl class="c360-business-timeline-item-fields">
                    @foreach($item->displayFields as $field)
                        <div class="c360-business-timeline-item-field">
                            <dt>{{ $field['label'] }}</dt>
                            <dd>{{ $field['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif

            @if($item->technicalFields !== [])
                <details class="c360-business-timeline-item-technical" data-timeline-technical-details>
                    <summary>Technical Details</summary>
                    <dl class="c360-business-timeline-item-fields c360-business-timeline-item-fields--technical">
                        @foreach($item->technicalFields as $field)
                            <div class="c360-business-timeline-item-field">
                                <dt>{{ $field['label'] }}</dt>
                                <dd>{{ $field['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </details>
            @endif

            @if(! $item->unifiedPresentation)
                <details class="c360-business-timeline-item-raw" data-timeline-raw-details>
                    <summary>Show Raw Events{{ $item->rawCount > 1 ? ' ('.$item->rawCount.')' : '' }}</summary>
                    <div class="c360-business-timeline-item-raw-list" role="list">
                        @foreach($item->rawEvents as $event)
                            <x-c360.activity-item :event="$event" :nestedRaw="true" />
                        @endforeach
                    </div>
                </details>
            @endif
        </div>
    </div>
</article>
