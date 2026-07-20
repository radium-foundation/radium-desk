@php
    /** @var \App\Data\Platform\PlatformCardPayload $card */
    $value = $card->meta['formatted_value'] ?? ($card->metrics[0]->value ?? '—');
    $trend = $card->meta['trend'] ?? null;
    $comparisonLabel = $card->meta['comparison_label'] ?? null;
    $trendDirection = $card->meta['trend_direction'] ?? null;
    $icon = $card->meta['icon'] ?? $card->icon;

    $trendClass = match ($trendDirection) {
        'positive' => 'text-success',
        'negative' => 'text-danger',
        'neutral' => 'text-muted',
        default => 'text-muted',
    };
@endphp

<div class="platform-executive-metric">
    @if(filled($icon))
        <div class="platform-executive-metric__icon text-muted mb-2" aria-hidden="true">
            <i class="bi {{ $icon }}"></i>
        </div>
    @endif
    <div class="platform-executive-metric__value">{{ $value }}</div>
    @if(filled($trend))
        <div class="platform-executive-metric__trend {{ $trendClass }} small mt-1">{{ $trend }}</div>
    @endif
    @if(filled($comparisonLabel) && filled($trend))
        <div class="platform-executive-metric__comparison text-muted small">{{ $comparisonLabel }}</div>
    @endif
</div>
