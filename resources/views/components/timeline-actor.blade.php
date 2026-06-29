@props([
    'actor',
    'class' => '',
])

@if($actor->isVisible())
    <span @class([$class])>
        {{ $actor->displayName }}
        @if($actor->subtitle)
            <span class="timeline-actor-subtitle">{{ $actor->subtitle }}</span>
        @endif
    </span>
@endif
