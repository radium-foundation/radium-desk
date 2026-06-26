import { mergeServiceCaseRows } from './live-dashboard-merge';
import { initTooltips } from './tooltips';
import { getWorkspaceSession } from './workspace/session';

const replaceInnerHtml = (elementId, html) => {
    const element = document.getElementById(elementId);

    if (!element || html === undefined) {
        return;
    }

    element.innerHTML = html;
};

let refreshInFlight = false;
let pendingDashboardRefresh = null;
let dashboardRefreshHooks = {};

const applyKpis = (kpiStripHtml) => {
    if (kpiStripHtml !== undefined) {
        replaceInnerHtml('dashboard-kpi-strip', kpiStripHtml);
    }
};

const applyRows = (rows, options = {}) => {
    const card = document.querySelector('.dashboard-service-cases-card');

    if (!card || rows === undefined) {
        return [];
    }

    const lockedIncidentIds = options.lockedIncidentIds
        ?? getWorkspaceSession().getLockedIncidentIds();

    const replacedIncidentIds = [];

    mergeServiceCaseRows(
        card,
        rows,
        Boolean(options.serviceCasesEmpty),
        options.serviceCasesEmptyHtml ?? '',
        initTooltips,
        {
            lockedIncidentIds,
            onRowsUpdated: (ids) => {
                replacedIncidentIds.push(...ids);
                dashboardRefreshHooks.onRowsUpdated?.(ids);
            },
        },
    );

    return replacedIncidentIds;
};

const removeRows = (incidentIds, lockedIncidentIds) => {
    incidentIds.forEach((incidentId) => {
        if (lockedIncidentIds.includes(Number(incidentId))) {
            return;
        }

        document.getElementById(`service-case-row-${incidentId}`)?.remove();
    });
};

const applyDashboardRefresh = (data) => new Promise((resolve) => {
    requestAnimationFrame(() => {
        if (getWorkspaceSession().isActive()) {
            queueDashboardRefresh(data);
            resolve();

            return;
        }

        applyKpis(data.kpi_strip_html);
        applyRows(data.rows ?? [], {
            serviceCasesEmpty: data.service_cases_empty,
            serviceCasesEmptyHtml: data.service_cases_empty_html,
        });

        resolve();
    });
});

const applyPartialDashboardUpdate = (data) => new Promise((resolve) => {
    requestAnimationFrame(() => {
        if (getWorkspaceSession().isActive()) {
            queueDashboardRefresh({
                kpi_strip_html: data.kpi_strip_html,
                rows: data.rows ?? [],
                service_cases_empty: data.service_cases_empty,
                service_cases_empty_html: data.service_cases_empty_html,
            });
            resolve();

            return;
        }

        const lockedIncidentIds = getWorkspaceSession().getLockedIncidentIds();

        applyKpis(data.kpi_strip_html);

        if (data.remove_incident_ids?.length) {
            removeRows(data.remove_incident_ids, lockedIncidentIds);
        }

        if (data.rows?.length) {
            applyRows(data.rows, { lockedIncidentIds });
        }

        resolve();
    });
});

const queueDashboardRefresh = (data) => {
    pendingDashboardRefresh = data;
};

const flushPendingDashboardRefresh = async () => {
    if (!pendingDashboardRefresh) {
        return;
    }

    const data = pendingDashboardRefresh;
    pendingDashboardRefresh = null;
    await applyDashboardRefresh(data);
};

const refreshDashboard = async (pageRoot) => {
    const liveUrl = pageRoot.dataset.liveUrl;
    const filter = pageRoot.dataset.liveFilter ?? 'pending_admin';

    if (!liveUrl || document.hidden || refreshInFlight) {
        return;
    }

    refreshInFlight = true;

    try {
        const response = await fetch(`${liveUrl}?filter=${encodeURIComponent(filter)}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            return;
        }

        const data = await response.json();

        if (getWorkspaceSession().isActive()) {
            queueDashboardRefresh(data);

            return;
        }

        await applyDashboardRefresh(data);
    } catch (error) {
        // Ignore transient network errors during background refresh.
    } finally {
        refreshInFlight = false;
    }
};

export const configureLiveDashboard = (hooks = {}) => {
    dashboardRefreshHooks = hooks;
};

let pollIntervalId = null;

const startPolling = (pageRoot, intervalMs) => {
    if (pollIntervalId !== null) {
        return;
    }

    pollIntervalId = window.setInterval(() => {
        refreshDashboard(pageRoot);
    }, intervalMs);
};

const stopPolling = () => {
    if (pollIntervalId === null) {
        return;
    }

    window.clearInterval(pollIntervalId);
    pollIntervalId = null;
};

export const initLiveDashboard = (hooks = {}) => {
    const pageRoot = document.getElementById('dashboard-page');

    if (!pageRoot?.dataset.liveUrl) {
        return { startPolling, stopPolling, pageRoot: null };
    }

    configureLiveDashboard(hooks);
    const session = getWorkspaceSession();

    session.onIdle(() => {
        flushPendingDashboardRefresh();
    });

    const intervalMs = Number(pageRoot.dataset.liveInterval ?? 30000);
    const liveMode = pageRoot.dataset.liveMode ?? 'poll';

    if (liveMode === 'poll') {
        startPolling(pageRoot, intervalMs);
    }

    return { startPolling: () => startPolling(pageRoot, intervalMs), stopPolling, pageRoot };
};

export {
    applyDashboardRefresh,
    applyKpis,
    applyPartialDashboardUpdate,
    applyRows,
    flushPendingDashboardRefresh,
    queueDashboardRefresh,
    refreshDashboard,
    startPolling,
    stopPolling,
};
