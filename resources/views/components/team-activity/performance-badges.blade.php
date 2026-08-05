@props([
    'badges' => [],
])

@php
    $items = is_array($badges) ? array_values($badges) : [];
@endphp

@if($items !== [])
    <span {{ $attributes->class(['team-activity-performance-badges']) }}>
        @foreach($items as $badge)
            @php
                $title = trim((string) ($badge['title'] ?? ''));
                $tooltip = trim((string) ($badge['tooltip'] ?? ''));
                $hint = $title !== '' && $tooltip !== '' ? $title."\n".$tooltip : ($title ?: $tooltip);
                $badgeKey = (string) ($badge['key'] ?? '');
            @endphp
            <span class="team-activity-performance-badge"
                  role="img"
                  @if($title !== '') aria-label="{{ $title }}" @endif
                  @if($hint !== '') title="{{ $hint }}" @endif
                  data-performance-badge="{{ $badgeKey }}">
                <x-team-activity.performance-badge-icon :badge-key="$badgeKey" />
            </span>
        @endforeach
    </span>
@endif
