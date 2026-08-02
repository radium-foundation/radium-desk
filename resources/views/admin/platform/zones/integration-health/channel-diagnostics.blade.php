@props([
    'card' => [],
    'settings_url' => null,
])

<section aria-labelledby="channel-diagnostics-{{ $card['key'] ?? 'channel' }}-heading">
    <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <h2 id="channel-diagnostics-{{ $card['key'] ?? 'channel' }}-heading" class="h5 mb-0">
            {{ $card['label'] ?? 'Channel' }}
        </h2>
        <span class="badge text-bg-{{ $card['badge_class'] ?? 'secondary' }}">
            {{ $card['status_label'] ?? 'Unknown' }}
        </span>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <p class="text-muted small mb-3">{{ $card['summary'] ?? ($card['detail'] ?? 'Channel configuration status.') }}</p>

            @if(filled($settings_url))
                <a href="{{ $settings_url }}" class="btn btn-sm btn-outline-secondary">Open System Settings</a>
            @endif
        </div>
    </div>
</section>
