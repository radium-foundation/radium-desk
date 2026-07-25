@props([
    'title' => 'Nothing here yet',
    'description' => null,
    'icon' => 'layout-dashboard',
])

@php
    use App\Support\Settings\SettingsIcon;
@endphp

<div {{ $attributes->merge(['class' => 'settings-center-empty-state']) }}>
    <div class="settings-center-empty-state__icon" aria-hidden="true">
        {!! SettingsIcon::render($icon) !!}
    </div>
    <h3 class="settings-center-empty-state__title">{{ $title }}</h3>
    @if(filled($description))
        <p class="settings-center-empty-state__description">{{ $description }}</p>
    @endif
    @if(isset($actions))
        <div class="settings-center-empty-state__actions">
            {{ $actions }}
        </div>
    @endif
</div>
