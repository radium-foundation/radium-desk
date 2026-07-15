@props([
    'event',
])

@php
    use App\Enums\TimelineEventType;
    use App\Services\RemarkMentionFormatter;
    use App\Support\Timeline\TimelineActorPresenter;

    $isInternalNote = $event->type === TimelineEventType::InternalNote;
    $mentionFormatter = app(RemarkMentionFormatter::class);
    $actorPresenter = TimelineActorPresenter::for($event->actor);
    $indicatorVariant = $event->indicatorVariant ?? 'muted';
    $hasCommunicationChannels = $event->communicationChannels !== [];
    $hasExpandedMetadata = $event->summaryFields !== [] || ($event->detail !== null && ! $isInternalNote);
@endphp

<article @class([
        'c360-activity-item',
        'c360-activity-item--' . $event->type->value,
        'c360-activity-item--indicator-' . $indicatorVariant,
    ])
         role="listitem"
         data-timeline-event
         @if($event->storyKey) data-timeline-story-key="{{ $event->storyKey }}" @endif
         data-timeline-filter="{{ implode(',', $event->allFilterTags()) }}">
    <div class="c360-activity-item-indicator" aria-hidden="true"></div>

    <div class="c360-activity-item-body">
        <div class="c360-activity-item-header">
            <h5 class="c360-activity-item-title">{{ $event->title }}</h5>
            <time class="c360-activity-item-time unified-timeline-time"
                  datetime="{{ $event->occurredAt->toIso8601String() }}"
                  title="{{ $event->exactTimestamp() }}">
                {{ $event->relativeTimestamp() }}
            </time>
        </div>

        @if(filled($event->contextLine))
            <p class="c360-activity-item-context">{{ $event->contextLine }}</p>
        @endif

        @if($hasCommunicationChannels)
            <ul class="c360-activity-item-channels" aria-label="Delivery channels">
                @foreach($event->communicationChannels as $channel)
                    <li @class([
                        'c360-activity-item-channel',
                        'is-success' => $channel['success'] ?? false,
                        'is-failed' => ! ($channel['success'] ?? false),
                    ])>
                        <span class="c360-activity-item-channel-mark" aria-hidden="true">
                            {{ ($channel['success'] ?? false) ? '✓' : '✖' }}
                        </span>
                        <span>{{ $channel['label'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if($isInternalNote && filled($event->noteBody))
            <div class="c360-activity-item-description unified-timeline-detail unified-timeline-note-body">
                {!! $mentionFormatter->format($event->noteBody) !!}
            </div>
        @elseif(! $hasCommunicationChannels && filled($event->summary) && ! $hasExpandedMetadata)
            <p class="c360-activity-item-context">{{ $event->summary }}</p>
        @endif

        @if($actorPresenter->compactLabel() !== '')
            <div class="c360-activity-item-actor unified-timeline-actor">
                <span class="timeline-actor-name">
                    <i class="bi {{ $actorPresenter->iconClass() }} c360-activity-item-actor-icon" aria-hidden="true"></i>
                    {{ $actorPresenter->compactLabel() }}
                </span>
            </div>
        @endif

        @if($isInternalNote && $event->mentionedUserNames !== [])
            <div class="c360-activity-item-mentions unified-timeline-note-mentions small text-muted">
                Mentioned: {{ implode(', ', $event->mentionedUserNames) }}
            </div>
        @endif

        @if($hasExpandedMetadata)
            <details class="c360-activity-item-metadata">
                <summary>View details</summary>
                @if($event->summaryFields !== [])
                    <dl class="c360-activity-item-metadata-fields">
                        @foreach($event->summaryFields as $field)
                            <div>
                                <dt>{{ $field['label'] }}</dt>
                                <dd>{{ $field['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
                @if(filled($event->detail))
                    <div class="c360-activity-item-metadata-detail">{{ $event->detail }}</div>
                @endif
            </details>
        @elseif($event->isDetailExpandable())
            <details class="c360-activity-item-metadata">
                <summary>View details</summary>
                <div class="c360-activity-item-metadata-detail">{{ $event->detail }}</div>
            </details>
        @elseif(filled($event->detail) && ! $isInternalNote)
            <p class="c360-activity-item-context">{{ $event->detail }}</p>
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
