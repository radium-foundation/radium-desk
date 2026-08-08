import { refreshDashboard } from './live-dashboard';

/** Debounce window for coalescing rapid hybrid row merges into one KPI reconcile. */
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

        // kpisOnly → GET /dashboard/live?kpis_only=1 (server skips Ready Queue row HTML).
        void refreshDashboard(pageRoot, 'hybrid-kpi-reconcile', { kpisOnly: true });
    }, HYBRID_KPI_RECONCILE_DEBOUNCE_MS);
};

export const resetHybridKpiReconcileForTests = () => {
    cancelHybridKpiReconcile();
};
