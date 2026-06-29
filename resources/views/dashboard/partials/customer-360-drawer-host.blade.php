<div id="customer360Drawer"
     class="customer-360-drawer"
     data-customer-360-drawer
     aria-hidden="true"
     role="dialog"
     aria-modal="true"
     aria-labelledby="customer360DrawerTitle">
    <div class="customer-360-drawer-backdrop" data-customer-360-backdrop aria-hidden="true"></div>
    <aside class="customer-360-drawer-panel" data-customer-360-panel>
        <header class="customer-360-drawer-header">
            <div>
                <h2 class="customer-360-drawer-title" id="customer360DrawerTitle">Customer 360</h2>
                <p class="customer-360-drawer-subtitle text-muted small mb-0" data-customer-360-subtitle></p>
            </div>
            <button type="button"
                    class="btn btn-sm btn-link customer-360-drawer-close dashboard-u-focus-ring"
                    data-customer-360-close
                    aria-label="Close Customer 360 drawer">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>
        <div class="customer-360-drawer-body" data-customer-360-body>
            <div class="customer-360-drawer-loading" data-customer-360-loading hidden>
                <div class="spinner-border spinner-border-sm text-secondary" role="status">
                    <span class="visually-hidden">Loading…</span>
                </div>
                <span class="text-muted small">Loading customer details…</span>
            </div>
            <div class="customer-360-drawer-error alert alert-danger d-none" data-customer-360-error role="alert"></div>
            <div data-customer-360-content-host></div>
        </div>
    </aside>
</div>
