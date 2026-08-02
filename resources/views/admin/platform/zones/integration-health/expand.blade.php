@props([
    'diagnostics' => [],
])

@php
    $partial = $diagnostics['partial'] ?? null;
@endphp

<div class="platform-integration-diagnostics" data-platform-integration-diagnostics="{{ $diagnostics['key'] ?? '' }}">
    @if(is_string($partial) && view()->exists($partial))
        @include($partial, [
            'health' => $diagnostics['health'] ?? [],
            'showActions' => (bool) ($diagnostics['show_actions'] ?? false),
            'card' => $diagnostics['card'] ?? [],
            'templates' => $diagnostics['templates'] ?? [],
            'template_statuses' => $diagnostics['template_statuses'] ?? [],
            'settings_url' => $diagnostics['settings_url'] ?? null,
        ])
    @else
        <p class="text-muted small mb-0">Diagnostics are unavailable for this integration.</p>
    @endif

    @if(! empty($diagnostics['secondary_links']) && is_array($diagnostics['secondary_links']))
        <div class="mt-3 d-flex flex-wrap gap-2">
            @foreach($diagnostics['secondary_links'] as $link)
                <a href="{{ $link['url'] ?? '#' }}" class="btn btn-sm btn-outline-secondary">
                    {{ $link['label'] ?? 'Open' }}
                </a>
            @endforeach
        </div>
    @endif
</div>
