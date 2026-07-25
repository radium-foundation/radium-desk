@props([
    'title',
    'subtitle' => null,
])

<header class="settings-center-header">
    <div class="settings-center-header__text">
        <h1 class="settings-center-header__title">{{ $title }}</h1>
        @if($subtitle)
            <p class="settings-center-header__subtitle">{{ $subtitle }}</p>
        @endif
    </div>
    @if(isset($actions))
        <div class="settings-center-header__actions">
            {{ $actions }}
        </div>
    @endif
</header>
