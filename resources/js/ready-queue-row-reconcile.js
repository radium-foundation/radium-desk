import { refreshDashboard } from './live-dashboard';
import { isViewingReadyQueue } from './ready-queue-count-delta';
import { isDashboardSearchActive } from './dashboard-search-mode';
import { isDashboardQuickFilterActive } from './dashboard-service-case-state';
import { getWorkspaceSession } from './workspace/session';

/** Debounce window — aligned with hybrid KPI reconcile coalescing. */
const READY_QUEUE_ROW_RECONCILE_DEBOUNCE_MS = 500;

let reconcileTimeoutId = null;
let lastRowMutationAt = 0;

export const cancelReadyQueueRowReconcile = () => {
    if (reconcileTimeoutId !== null) {
        window.clearTimeout(reconcileTimeoutId);
        reconcileTimeoutId = null;
    }
};

const shouldScheduleReadyQueueRowReconcile = (pageRoot) => {
    if (!pageRoot || !isViewingReadyQueue(pageRoot)) {
        return false;
    }

    if (document.hidden) {
        return false;
    }

    if (isDashboardSearchActive() || isDashboardQuickFilterActive()) {
        return false;
    }

    if (getWorkspaceSession().isActive()) {
        return false;
    }

    if (pageRoot.dataset.operationsEmbeddedActive === '1') {
        return false;
    }

    return true;
};

/**
 * Debounced full row catch-up for the active Ready Queue window.
 * Uses existing refreshDashboard coalescing — not a separate polling loop.
 */
export const scheduleReadyQueueRowReconcile = (pageRoot) => {
    if (!shouldScheduleReadyQueueRowReconcile(pageRoot)) {
        return;
    }

    cancelReadyQueueRowReconcile();

    reconcileTimeoutId = window.setTimeout(() => {
        reconcileTimeoutId = null;

        const activePageRoot = document.getElementById('dashboard-page') ?? pageRoot;

        if (!shouldScheduleReadyQueueRowReconcile(activePageRoot)) {
            return;
        }

        void refreshDashboard(activePageRoot, 'ready-queue-row-reconcile');
    }, READY_QUEUE_ROW_RECONCILE_DEBOUNCE_MS);
};

/**
 * Call after applyPartialDashboardUpdate when authoritative filter counts changed
 * but no row patch/remove ran in the same payload.
 */
export const notifyReadyQueueRowMutated = () => {
    lastRowMutationAt = Date.now();
    cancelReadyQueueRowReconcile();
};

const hadRecentReadyQueueRowMutation = () => (
    Date.now() - lastRowMutationAt < READY_QUEUE_ROW_RECONCILE_DEBOUNCE_MS
);

export const maybeScheduleReadyQueueRowReconcileAfterCounts = (
    pageRoot,
    data,
    { hadRowMutation = false } = {},
) => {
    if (hadRowMutation) {
        notifyReadyQueueRowMutated();

        return;
    }

    if (hadRecentReadyQueueRowMutation()) {
        return;
    }

    const counts = data?.service_case_filter_counts;

    if (!counts || typeof counts !== 'object'
        || !Object.prototype.hasOwnProperty.call(counts, 'action_required')) {
        return;
    }

    scheduleReadyQueueRowReconcile(pageRoot ?? document.getElementById('dashboard-page'));
};

export const resetReadyQueueRowReconcileForTests = () => {
    cancelReadyQueueRowReconcile();
    lastRowMutationAt = 0;
};
