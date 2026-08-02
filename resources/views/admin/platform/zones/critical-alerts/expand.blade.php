@props([
    'alert',
])

@php
    /** @var \App\Data\Platform\PlatformAlert $alert */
@endphp

<div class="platform-critical-alert-detail border rounded-3 p-3 bg-body-tertiary">
    <div class="d-flex align-items-center gap-2 mb-2">
        <span class="badge text-bg-{{ $alert->severity->badgeClass() }}">{{ $alert->severity->label() }}</span>
        <strong>{{ $alert->title }}</strong>
    </div>
    <p class="small mb-2">{{ $alert->summary }}</p>
    <div class="text-muted small mb-3">
        Source: <code>{{ $alert->source }}</code>
        @if($alert->lastUpdated)
            · Last Updated {{ \App\Support\AppDateFormatter::format($alert->lastUpdated, 'g:i A') }}
        @endif
    </div>

    @if($alert->related !== [])
        <h3 class="h6">Related issues</h3>
        <ul class="small mb-0">
            @foreach($alert->related as $related)
                <li>
                    <strong>{{ $related['title'] ?? 'Issue' }}</strong>
                    — {{ $related['summary'] ?? '' }}
                    @if(! empty($related['severity']))
                        <span class="text-muted">({{ $related['severity'] }})</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
