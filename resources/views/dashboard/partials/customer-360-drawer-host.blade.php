<div id="customer360Drawer"
     class="customer-360-drawer"
     data-customer-360-drawer
     data-timeline-poll-ms="{{ $customer360TimelinePollIntervalMs ?? 30000 }}"
     data-device-sync-poll-ms="{{ $customer360DeviceSyncPollIntervalMs ?? 10000 }}"
     aria-hidden="true"
     role="dialog"
     aria-modal="true"
     aria-label="Customer 360 Operations Cockpit">
    <div class="customer-360-drawer-backdrop" data-customer-360-backdrop aria-hidden="true"></div>
    <aside class="customer-360-drawer-panel" data-customer-360-panel>
        <header class="customer-360-drawer-header customer-360-drawer-header--cockpit">
            <span class="visually-hidden" id="customer360DrawerTitle">Customer 360</span>
            <p class="customer-360-drawer-subtitle text-muted small mb-0" data-customer-360-subtitle hidden></p>
            <button type="button"
                    class="btn btn-sm btn-link customer-360-drawer-close dashboard-u-focus-ring ms-auto"
                    data-customer-360-close
                    aria-label="Close Customer 360 drawer">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>
        <div class="customer-360-drawer-body" data-customer-360-body>
            <div class="customer-360-drawer-loading" data-customer-360-loading hidden>
                <x-c360.skeleton variant="chips" />
                <x-c360.skeleton variant="ira" :lines="3" class="mt-3" />
            </div>
            <div class="customer-360-drawer-error alert alert-danger d-none" data-customer-360-error role="alert"></div>
            <div data-customer-360-content-host></div>
        </div>
        <x-c360.command-palette />
        <x-c360.shortcut-help />
    </aside>
</div>
