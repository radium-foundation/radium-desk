@props([
    'event',
])

@php
    use App\Enums\TimelineEventType;
    use App\Services\RemarkMentionFormatter;

    $isInternalNote = $event->type === TimelineEventType::InternalNote;
    $hasStructuredSummary = $event->summaryFields !== [];
    $mentionFormatter = app(RemarkMentionFormatter::class);

    $description = null;

    if ($isInternalNote && filled($event->noteBody)) {
        $description = $event->noteBody;
    } elseif ($hasStructuredSummary) {
        $description = collect($event->summaryFields)
            ->map(fn (array $field): string => "{$field['label']}: {$field['value']}")
            ->implode("\n");
    } elseif ($event->isDetailExpandable()) {
        $description = $event->collapsedDetailPreview();
    } elseif (filled($event->detail)) {
        $description = $event->detail;
    } elseif (filled($event->summary)) {
        $description = $event->summary;
    }
@endphp

<article @class([
        'c360-activity-item',
        'c360-activity-item--' . $event->type->value,
    ])
         role="listitem"
         data-timeline-event
         data-timeline-filter="{{ implode(',', $event->allFilterTags()) }}">
    <div class="c360-activity-item-indicator" aria-hidden="true"></div>

    <div class="c360-activity-item-icon unified-timeline-icon unified-timeline-icon--{{ $event->type->value }}"
         aria-hidden="true">
        <i class="bi {{ $event->iconClass() }}"></i>
    </div>

    <div class="c360-activity-item-body">
        <div class="c360-activity-item-header">
            <div class="c360-activity-item-heading">
                <span class="c360-activity-item-type">{{ $event->type->label() }}</span>
                <h5 class="c360-activity-item-title">{{ $event->title }}</h5>
            </div>
            <time class="c360-activity-item-time unified-timeline-time"
                  datetime="{{ $event->occurredAt->toIso8601String() }}"
                  title="{{ $event->exactTimestamp() }}">
                @if($isInternalNote)
                    {{ display_app_timeline_datetime($event->occurredAt) }}
                @else
                    {{ $event->relativeTimestamp() }}
                @endif
            </time>
        </div>

        @if($description !== null)
            <div class="c360-activity-item-description unified-timeline-detail">
                @if($isInternalNote)
                    {!! $mentionFormatter->format($description) !!}
                @else
                    {{ $description }}
                @endif
            </div>
        @endif

        @if($event->actor->isVisible())
            <div class="c360-activity-item-actor unified-timeline-actor">
                <span @class([
                    'timeline-actor-badge',
                    'timeline-actor-badge--' . $event->actor->roleVariant(),
                ])>{{ $event->actor->roleLabel() }}</span>
                <x-timeline-actor :actor="$event->actor" class="timeline-actor-name" />
            </div>
        @endif

        @if($isInternalNote && $event->mentionedUserNames !== [])
            <div class="c360-activity-item-mentions unified-timeline-note-mentions small text-muted">
                Mentioned: {{ implode(', ', $event->mentionedUserNames) }}
            </div>
        @endif

        @if($event->statusLabel)
            @php
                $statusVariant = match ($event->statusVariant ?? 'pending') {
                    'success', 'sent', 'completed' => 'success',
                    'failed', 'danger' => 'danger',
                    'warning' => 'warning',
                    default => 'info',
                };
                $statusIcon = match ($statusVariant) {
                    'success' => '✓',
                    'danger' => '✖',
                    'warning' => '⚠',
                    default => 'ⓘ',
                };
            @endphp
            <x-c360.status-banner :variant="$statusVariant" :icon="$statusIcon" class="c360-status-banner--compact">
                {{ $event->statusLabel }}
            </x-c360.status-banner>
        @endif

        @if($event->actionUrl && $event->actionLabel)
            <a href="{{ $event->actionUrl }}"
               target="_blank"
               rel="noopener noreferrer"
               class="unified-timeline-action-link">
                {{ $event->actionLabel }}
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
            </a>
        @endif
    </div>
</article>
