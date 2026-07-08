@php
    $appName = config('app.name', 'Radium Desk');
    $version = config('app.version', '0.0.0');
@endphp

<div class="app-version-footer px-3 py-2">
    <button
        type="button"
        class="app-version-footer__button btn btn-link p-0 text-decoration-none"
        data-bs-toggle="modal"
        data-bs-target="#whatsNewModal"
        title="What's New"
    >
        <span class="app-version-footer__label">{{ $appName }} v{{ $version }}</span>
    </button>
</div>
