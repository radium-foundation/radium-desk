@props([
    'title' => null,
    'description' => null,
    'icon' => null,
    'status' => null,
    'statusTone' => 'neutral',
    'flush' => false,
])

<div {{ $attributes->merge(['class' => 'settings-center-card']) }}>
    @if($title || $icon || $status || isset($headerActions))
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
            <div class="settings-center-card__header-meta">
                @if($status)
                    <x-settings-center.status-pill :label="$status" :tone="$statusTone" size="sm" />
                @endif
                @if(isset($headerActions))
                    <div class="settings-center-card__header-actions">
                        {{ $headerActions }}
                    </div>
                @endif
            </div>
        </header>
    @endif
    <div @class(['settings-center-card__body', 'settings-center-card__body--flush' => $flush])>
        {{ $slot }}
    </div>
    @if(isset($footer))
        <footer class="settings-center-card__footer">
            {{ $footer }}
        </footer>
    @endif
</div>
