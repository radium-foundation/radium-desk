@props([
    'event',
])

<article class="unified-timeline-item" role="listitem" data-timeline-event>
    <div class="unified-timeline-icon unified-timeline-icon--{{ $event->type->value }}" aria-hidden="true">
        <i class="bi {{ $event->iconClass() }}"></i>
    </div>
    <div class="unified-timeline-content">
        <time class="unified-timeline-time"
              datetime="{{ $event->occurredAt->toIso8601String() }}"
              title="{{ $event->exactTimestamp() }}">
            {{ $event->relativeTimestamp() }}
        </time>
        <div class="unified-timeline-title">{{ $event->title }}</div>
        @if($event->actor->isVisible())
            <div class="unified-timeline-actor">
                <x-timeline-actor :actor="$event->actor" />
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
