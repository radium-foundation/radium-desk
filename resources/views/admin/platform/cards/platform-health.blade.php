@php
    /** @var \App\Data\Platform\PlatformCardPayload $card */
    $components = is_array($card->meta['components'] ?? null) ? $card->meta['components'] : [];
@endphp

<div class="platform-health-components">
    @forelse($components as $healthComponent)
        <div class="d-flex justify-content-between align-items-start gap-3 py-2 border-bottom">
            <div class="min-w-0">
                <div class="fw-semibold small">{{ $healthComponent['label'] ?? '' }}</div>
                <div class="text-muted small">{{ $healthComponent['detail'] ?? '' }}</div>
            </div>
            <div class="flex-shrink-0">
                <x-platform.status-badge
                    :status="$healthComponent['status'] ?? 'disabled'"
                    :label="$healthComponent['status_label'] ?? null"
                />
            </div>
        </div>
    @empty
        <p class="text-muted small mb-0">No health providers registered.</p>
    @endforelse
</div>
