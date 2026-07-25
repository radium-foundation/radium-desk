@php
    /** @var \App\Data\Platform\PlatformCardPayload $card */
    $components = is_array($card->meta['components'] ?? null) ? $card->meta['components'] : [];
@endphp

<div class="settings-center-platform-metrics settings-center-platform-metrics--health">
    @forelse($components as $healthComponent)
        <div class="settings-center-platform-metric">
            <div class="settings-center-platform-metric__text">
                <div class="settings-center-platform-metric__label">{{ $healthComponent['label'] ?? '' }}</div>
                <div class="settings-center-platform-metric__detail">{{ $healthComponent['detail'] ?? '' }}</div>
            </div>
            <div class="settings-center-platform-metric__value">
                <x-platform.status-badge
                    :status="$healthComponent['status'] ?? 'disabled'"
                    :label="$healthComponent['status_label'] ?? null"
                />
            </div>
        </div>
    @empty
        <p class="settings-center-platform-metric__empty">No health providers registered.</p>
    @endforelse
</div>
