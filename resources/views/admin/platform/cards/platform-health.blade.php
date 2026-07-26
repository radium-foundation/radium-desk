@php
    /** @var \App\Data\Platform\PlatformCardPayload $card */
    use App\Enums\PlatformHealthStatus;

    $components = is_array($card->meta['components'] ?? null) ? $card->meta['components'] : [];
    $allHealthy = $components !== [] && collect($components)->every(
        fn (array $component): bool => ($component['status'] ?? '') === PlatformHealthStatus::Healthy->value,
    );
@endphp

<div class="settings-center-platform-health">
    @if($allHealthy)
        <div class="settings-center-platform-health__summary settings-center-platform-health__summary--ok">
            <x-settings-center.icon name="check" class="settings-center-icon settings-center-icon--sm" />
            <span>All Systems Operational</span>
        </div>
    @endif

    <div @class([
        'settings-center-platform-health__grid',
        'settings-center-platform-health__grid--compact' => $allHealthy,
    ])>
        @forelse($components as $healthComponent)
            @php
                $componentStatus = PlatformHealthStatus::tryFrom((string) ($healthComponent['status'] ?? ''));
                $isComponentHealthy = $componentStatus === PlatformHealthStatus::Healthy;
                $statusLabel = $healthComponent['status_label'] ?? $componentStatus?->label() ?? 'Unknown';
                $detail = $healthComponent['detail'] ?? '';
            @endphp
            <div @class([
                'settings-center-platform-health__row',
                'settings-center-platform-health__row--degraded' => ! $isComponentHealthy,
            ])>
                <span @class([
                    'settings-center-platform-health__dot',
                    'settings-center-platform-health__dot--'.$componentStatus?->badgeClass(),
                ]) aria-hidden="true"></span>
                <div class="settings-center-platform-health__name">{{ $healthComponent['label'] ?? '' }}</div>
                <div class="settings-center-platform-health__status">{{ $statusLabel }}</div>
                @if(! $isComponentHealthy && filled($detail))
                    <div class="settings-center-platform-health__detail">{{ $detail }}</div>
                @endif
            </div>
        @empty
            <p class="settings-center-platform-health__empty">No health providers registered.</p>
        @endforelse
    </div>
</div>
