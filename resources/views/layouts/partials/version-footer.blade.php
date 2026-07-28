<div class="app-version-footer px-3 py-2">
    <button
        type="button"
        class="app-version-footer__button btn btn-link p-0 text-decoration-none"
        data-bs-toggle="modal"
        data-bs-target="#whatsNewModal"
        data-short-version="{{ $shortVersionLabel }}"
        title="{{ $footerTitle }}"
    >
        <span class="app-version-footer__label">
            <span class="app-version-footer__name">{{ $applicationLabel }}</span>
            @if(filled($buildLabel ?? null))
                <span class="app-version-footer__build">{{ $buildLabel }}</span>
            @endif
        </span>
    </button>
</div>
