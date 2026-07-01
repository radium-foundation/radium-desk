@props([
    'event',
])

<article class="unified-timeline-item unified-timeline-item--card"
         role="listitem"
         data-timeline-event
         data-timeline-filter="{{ $event->filterCategory() }}">
    <div class="unified-timeline-item-header">
        <div class="unified-timeline-icon unified-timeline-icon--{{ $event->type->value }}" aria-hidden="true">
            <i class="bi {{ $event->iconClass() }}"></i>
        </div>
        <div class="unified-timeline-item-badges">
            <span class="unified-timeline-type-badge">{{ $event->type->label() }}</span>
            @if($event->statusLabel)
                <span @class([
                    'timeline-status-chip',
                    'timeline-status-chip--' . ($event->statusVariant ?? 'pending'),
                ])>{{ $event->statusLabel }}</span>
            @endif
        </div>
        <time class="unified-timeline-time"
              datetime="{{ $event->occurredAt->toIso8601String() }}"
              title="{{ $event->exactTimestamp() }}">
            {{ $event->relativeTimestamp() }}
        </time>
    </div>

    <div class="unified-timeline-content">
        <div class="unified-timeline-title">{{ $event->title }}</div>

        @if($event->actor->isVisible())
            <div class="unified-timeline-actor">
                <span @class([
                    'timeline-actor-badge',
                    'timeline-actor-badge--' . $event->actor->roleVariant(),
                ])>{{ $event->actor->roleLabel() }}</span>
                <x-timeline-actor :actor="$event->actor" class="timeline-actor-name" />
            </div>
        @endif

        @if($event->isDetailExpandable())
            <details class="unified-timeline-details">
                <summary class="unified-timeline-details-summary">{{ $event->collapsedDetailPreview() }}</summary>
                <div class="unified-timeline-details-body">{{ $event->detail }}</div>
            </details>
        @elseif($event->detail !== null)
            <div class="unified-timeline-detail">{{ $event->detail }}</div>
        @elseif($event->summary !== null)
            <div class="unified-timeline-detail">{{ $event->summary }}</div>
        @endif
    </div>
</article>
