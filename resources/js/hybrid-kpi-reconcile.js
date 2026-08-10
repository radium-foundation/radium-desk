import { reconcileViewOnlyMetrics } from './dashboard-live-counts';

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

        // Stale-aware lightweight counts only — never GET /dashboard/live.
        void reconcileViewOnlyMetrics(pageRoot);
    }, HYBRID_KPI_RECONCILE_DEBOUNCE_MS);
};

export const resetHybridKpiReconcileForTests = () => {
    cancelHybridKpiReconcile();
};
