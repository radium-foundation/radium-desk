@php
    /** @var \App\Data\Platform\PlatformCardPayload $card */
    $value = $card->meta['formatted_value'] ?? ($card->metrics[0]->value ?? '—');
    $trend = $card->meta['trend'] ?? null;
    $comparisonLabel = $card->meta['comparison_label'] ?? null;
    $trendDirection = $card->meta['trend_direction'] ?? null;
    $icon = $card->meta['icon'] ?? $card->icon;

    $trendClass = match ($trendDirection) {
        'positive' => 'settings-center-platform-executive__trend--positive',
        'negative' => 'settings-center-platform-executive__trend--negative',
        'neutral' => 'settings-center-platform-executive__trend--neutral',
        default => 'settings-center-platform-executive__trend--neutral',
    };
@endphp

<div class="settings-center-platform-executive">
    @if(filled($icon))
        <div class="settings-center-platform-executive__icon" aria-hidden="true">
            <i class="bi {{ $icon }}"></i>
        </div>
    @endif
    <div class="settings-center-platform-executive__value">{{ $value }}</div>
    @if(filled($trend))
        <div class="settings-center-platform-executive__trend {{ $trendClass }}">{{ $trend }}</div>
    @endif
    @if(filled($comparisonLabel) && filled($trend))
        <div class="settings-center-platform-executive__comparison">{{ $comparisonLabel }}</div>
    @endif
</div>
