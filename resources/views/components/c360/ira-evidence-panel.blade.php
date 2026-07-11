@props([
    'items' => [],
])

@php
    $items = is_array($items) ? array_values(array_filter($items, fn ($item) => filled($item['title'] ?? null))) : [];
@endphp

@if($items !== [])
    <ul {{ $attributes->merge(['class' => 'c360-ira-evidence']) }} role="list">
        @foreach($items as $item)
            @php
                $tone = $item['tone'] ?? 'positive';
                $icon = match ($tone) {
                    'warning' => '⚠',
                    'negative' => '✖',
                    default => '✓',
                };
            @endphp
            <li @class(['c360-ira-evidence-item', 'c360-ira-evidence-item--' . $tone]) role="listitem">
                <span class="c360-ira-evidence-icon" aria-hidden="true">{{ $icon }}</span>
                <span class="c360-ira-evidence-copy">
                    <span class="c360-ira-evidence-title">{{ $item['title'] }}</span>
                    @if(filled($item['source'] ?? null))
                        <span class="c360-ira-evidence-source">{{ $item['source'] }}</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>
@endif
