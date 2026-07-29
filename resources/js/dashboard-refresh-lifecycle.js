let refreshInFlightSnapshot = false;
let pendingDashboardRefreshSnapshot = false;
let pendingLiveRefreshSnapshot = false;
let workspaceSessionActiveSnapshot = false;
let workspaceActiveReasonsSnapshot = [];

export const setRefreshLifecycleState = ({
    refreshInFlight = false,
    pendingDashboardRefresh = false,
    pendingLiveRefresh = false,
    workspaceSessionActive = false,
    workspaceActiveReasons = [],
} = {}) => {
    refreshInFlightSnapshot = refreshInFlight;
    pendingDashboardRefreshSnapshot = pendingDashboardRefresh;
    pendingLiveRefreshSnapshot = pendingLiveRefresh;
    workspaceSessionActiveSnapshot = workspaceSessionActive;
    workspaceActiveReasonsSnapshot = Array.isArray(workspaceActiveReasons)
        ? [...workspaceActiveReasons]
        : [];
};

export const isRefreshLifecycleDebugEnabled = (pageRoot) => (
    pageRoot?.dataset.realtimeLifecycleDebug === '1'
    || pageRoot?.dataset.realtimeDebug === '1'
);

export const logRefreshLifecycle = (pageRoot, event, detail = null) => {
    if (! isRefreshLifecycleDebugEnabled(pageRoot)) {
        return;
    }

    const payload = {
        event,
        at: new Date().toISOString(),
        refreshInFlight: refreshInFlightSnapshot,
        pendingDashboardRefresh: pendingDashboardRefreshSnapshot,
        pendingLiveRefresh: pendingLiveRefreshSnapshot,
        workspaceSessionActive: workspaceSessionActiveSnapshot,
        workspaceActiveReasons: workspaceActiveReasonsSnapshot,
        documentHidden: document.hidden,
        navigatorOnLine: typeof navigator !== 'undefined' ? navigator.onLine : null,
        ...(detail ?? {}),
    };

    console.warn('[dashboard-refresh-lifecycle]', payload);
};
