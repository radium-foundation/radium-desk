import { reconcileViewOnlyMetrics } from './dashboard-live-counts';
import { refreshDashboard } from './live-dashboard';

/** Debounce window for coalescing rapid hybrid row merges into one view-only metric reconcile. */
const HYBRID_KPI_RECONCILE_DEBOUNCE_MS = 500;

let reconcileTimeoutId = null;

export const cancelHybridKpiReconcile = () => {
    if (reconcileTimeoutId !== null) {
        window.clearTimeout(reconcileTimeoutId);
        reconcileTimeoutId = null;
    }
};

export const scheduleHybridKpiReconcile = (pageRoot) => {
    cancelHybridKpiReconcile();

    reconcileTimeoutId = window.setTimeout(() => {
        reconcileTimeoutId = null;

        // View-only SQL metrics (active_cases, pending_refunds) via /dashboard/live/counts.
        void reconcileViewOnlyMetrics(pageRoot);

        // Authoritative queue filter counts (action_required, etc.) via kpis_only — no row HTML.
        void refreshDashboard(pageRoot, 'hybrid-kpi-reconcile', { kpisOnly: true });
    }, HYBRID_KPI_RECONCILE_DEBOUNCE_MS);
};

export const resetHybridKpiReconcileForTests = () => {
    cancelHybridKpiReconcile();
};
