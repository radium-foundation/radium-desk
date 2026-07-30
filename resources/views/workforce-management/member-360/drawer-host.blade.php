<div
    id="workforceMember360Drawer"
    class="wm360-drawer"
    data-wm360-drawer
    data-wm360-endpoint-template="{{ url('/workforce-management/members/__USER__') }}"
    aria-hidden="true"
    role="dialog"
    aria-modal="true"
    aria-label="Workforce Member 360"
>
    <div class="wm360-drawer__backdrop" data-wm360-backdrop aria-hidden="true"></div>
    <aside class="wm360-drawer__panel" data-wm360-panel>
        <header class="wm360-drawer__chrome">
            <div class="wm360-drawer__chrome-title">Workforce Member 360</div>
            <button
                type="button"
                class="btn btn-sm btn-link wm360-drawer__close"
                data-wm360-close
                aria-label="Close Workforce Member 360"
            >
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>
        <div class="wm360-drawer__body" data-wm360-body>
            <div class="wm360-drawer__loading" data-wm360-loading hidden>
                <div class="wm360-skeleton wm360-skeleton--header"></div>
                <div class="wm360-skeleton wm360-skeleton--block"></div>
                <div class="wm360-skeleton wm360-skeleton--block"></div>
            </div>
            <div class="wm360-drawer__error alert alert-danger d-none" data-wm360-error role="alert"></div>
            <div data-wm360-content-host></div>
        </div>
    </aside>
</div>
