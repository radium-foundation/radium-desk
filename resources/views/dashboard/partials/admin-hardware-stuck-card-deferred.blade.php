@php
    $refreshMs = max(5000, (int) config('dashboard.hardware_counts_ttl_seconds', 30) * 1000);
@endphp

<div class="dashboard-kpi-item dashboard-u-surface-card admin-hardware-stuck-kpi admin-hardware-stuck-kpi--deferred"
     role="group"
     aria-label="Hardware stuck orders"
     aria-busy="true"
     data-hardware-stuck-summary
     data-hardware-stuck-url="{{ route('dashboard.hardware.stuck-summary') }}"
     data-hardware-stuck-refresh-ms="{{ $refreshMs }}">
    <div class="admin-hardware-stuck-kpi__head">
        <div class="dashboard-kpi-icon text-danger">
            <i class="bi bi-cpu" aria-hidden="true"></i>
        </div>
        <div class="dashboard-kpi-content">
            <div class="dashboard-kpi-label">Hardware Stuck Orders</div>
            <div class="dashboard-kpi-value">
                <span class="admin-hardware-stuck-kpi__loading text-secondary" data-hardware-stuck-loading>
                    Loading…
                </span>
            </div>
            <p class="admin-hardware-stuck-kpi__meta mb-0 text-secondary" data-hardware-stuck-status>
                Fetching fulfilment blocker counts…
            </p>
        </div>
    </div>
    <p class="admin-hardware-stuck-kpi__error small text-danger mb-0 d-none" data-hardware-stuck-error hidden>
        Unable to load stuck order counts. This does not block other dashboard metrics.
    </p>
</div>
