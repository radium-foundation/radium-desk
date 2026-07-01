@props([
    'event',
])

@php
    use App\Enums\TimelineEventType;
    use App\Services\RemarkMentionFormatter;

    $isInternalNote = $event->type === TimelineEventType::InternalNote;
    $isWhatsAppSummary = $event->type === TimelineEventType::WhatsApp && $event->summaryFields !== [];
    $isWhatsAppTemplateSent = $event->type === TimelineEventType::WhatsAppTemplateSent && $event->summaryFields !== [];
    $isStructuredSummary = $isWhatsAppSummary || $isWhatsAppTemplateSent;
    $mentionFormatter = app(RemarkMentionFormatter::class);
@endphp

<article @class([
        'unified-timeline-item unified-timeline-item--card',
        'unified-timeline-item--whatsapp-summary' => $isWhatsAppSummary,
        'unified-timeline-item--whatsapp-template-sent' => $isWhatsAppTemplateSent,
    ])
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
            @if($isInternalNote)
                {{ display_app_timeline_datetime($event->occurredAt) }}
            @else
                {{ $event->relativeTimestamp() }}
            @endif
        </time>
    </div>

    <div class="unified-timeline-content">
        <div class="unified-timeline-title">{{ $event->title }}</div>

        @if($isInternalNote && filled($event->noteBody))
            <div class="unified-timeline-detail unified-timeline-note-body">{!! $mentionFormatter->format($event->noteBody) !!}</div>
        @endif

        @if($isStructuredSummary)
            <dl class="unified-timeline-summary-fields">
                @foreach($event->summaryFields as $field)
                    <div class="unified-timeline-summary-field">
                        <dt>{{ $field['label'] }}</dt>
                        <dd>{{ $field['value'] }}</dd>
                    </div>
                @endforeach
            </dl>

            @if($event->actionUrl && $event->actionLabel)
                <a href="{{ $event->actionUrl }}"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="unified-timeline-action-link">
                    {{ $event->actionLabel }}
                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                </a>
            @endif
        @else
            @if($event->actor->isVisible())
                <div class="unified-timeline-actor">
                    @unless($isInternalNote)
                        <span @class([
                            'timeline-actor-badge',
                            'timeline-actor-badge--' . $event->actor->roleVariant(),
                        ])>{{ $event->actor->roleLabel() }}</span>
                    @endunless
                    <x-timeline-actor :actor="$event->actor" class="timeline-actor-name" />
                </div>
            @endif

            @if($isInternalNote && $event->mentionedUserNames !== [])
                <div class="unified-timeline-note-mentions small text-muted">
                    Mentioned: {{ implode(', ', $event->mentionedUserNames) }}
                </div>
            @endif

            @unless($isInternalNote)
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
            @endunless
        @endif
    </div>
</article>
