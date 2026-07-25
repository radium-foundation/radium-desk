@props([
    'title' => null,
    'description' => null,
    'icon' => null,
    'status' => null,
    'statusTone' => 'neutral',
])

<div {{ $attributes->merge(['class' => 'settings-center-card']) }}>
    @if($title || $icon || $status)
        <header class="settings-center-card__header">
            <div class="settings-center-card__heading">
                @if($icon)
                    <span class="settings-center-card__icon" aria-hidden="true">
                        {!! \App\Support\Settings\SettingsIcon::render($icon) !!}
                    </span>
                @endif
                <div>
                    @if($title)
                        <h2 class="settings-center-card__title">{{ $title }}</h2>
                    @endif
                    @if($description)
                        <p class="settings-center-card__description">{{ $description }}</p>
                    @endif
                </div>
            </div>
            @if($status)
                <span @class([
                    'settings-center-status-pill',
                    'settings-center-status-pill--'.$statusTone,
                ])>
                    <span class="settings-center-status-pill__dot" aria-hidden="true"></span>
                    {{ $status }}
                </span>
            @endif
        </header>
    @endif
    <div class="settings-center-card__body">
        {{ $slot }}
    </div>
    @if(isset($footer))
        <footer class="settings-center-card__footer">
            {{ $footer }}
        </footer>
    @endif
</div>
