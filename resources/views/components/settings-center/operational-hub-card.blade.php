@props([
    'icon',
    'title',
    'status',
    'statusTone' => 'success',
    'description',
    'primaryLabel',
    'primaryHref' => null,
    'secondaryLabel' => null,
    'secondaryHref' => null,
])

<div class="settings-center-op-card" {{ $attributes }}>
    <div class="settings-center-op-card__header">
        <span class="settings-center-op-card__icon" aria-hidden="true">
            {!! \App\Support\Settings\SettingsIcon::render($icon) !!}
        </span>
        <div class="settings-center-op-card__meta">
            <h3 class="settings-center-op-card__title">{{ $title }}</h3>
            <span @class([
                'settings-center-status-pill settings-center-status-pill--sm',
                'settings-center-status-pill--'.$statusTone,
            ])>
                <span class="settings-center-status-pill__dot" aria-hidden="true"></span>
                {{ $status }}
            </span>
        </div>
    </div>
    <p class="settings-center-op-card__description">{{ $description }}</p>
    <div class="settings-center-op-card__actions">
        @if($primaryHref)
            <a href="{{ $primaryHref }}" class="btn btn-sm btn-primary">{{ $primaryLabel }}</a>
        @else
            <button type="button" class="btn btn-sm btn-primary" {{ $attributes->merge(['data-settings-op-configure' => '']) }}>{{ $primaryLabel }}</button>
        @endif
        @if($secondaryLabel)
            @if($secondaryHref)
                <a href="{{ $secondaryHref }}" class="btn btn-sm btn-outline-secondary">{{ $secondaryLabel }}</a>
            @else
                <button type="button" class="btn btn-sm btn-outline-secondary">{{ $secondaryLabel }}</button>
            @endif
        @endif
    </div>
</div>
