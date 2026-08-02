@props([
    'diagnostics' => [],
])

@php
    $partial = $diagnostics['partial'] ?? null;
@endphp

<div class="platform-summary-diagnostics" data-platform-summary-diagnostics="{{ $diagnostics['key'] ?? '' }}">
    @if(is_string($partial) && view()->exists($partial))
        @include($partial, array_merge(
            [
                'health' => $diagnostics['health'] ?? [],
                'metrics' => $diagnostics['metrics'] ?? [],
                'failures' => $diagnostics['failures'] ?? [],
                'showActions' => false,
            ],
            is_array($diagnostics['view_data'] ?? null) ? $diagnostics['view_data'] : []
        ))
    @elseif(! empty($diagnostics['html']))
        {!! $diagnostics['html'] !!}
    @else
        <p class="text-muted small mb-0">{{ $diagnostics['message'] ?? 'Details are unavailable.' }}</p>
    @endif

    @if(! empty($diagnostics['links']) && is_array($diagnostics['links']))
        <div class="mt-3 d-flex flex-wrap gap-2">
            @foreach($diagnostics['links'] as $link)
                <a href="{{ $link['url'] ?? '#' }}" class="btn btn-sm btn-outline-secondary">
                    {{ $link['label'] ?? 'Open' }}
                </a>
            @endforeach
        </div>
    @endif
</div>
