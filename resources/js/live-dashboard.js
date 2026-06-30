import { mergeServiceCaseRows } from './live-dashboard-merge';
import { initTooltips } from './tooltips';
import { isDashboardSearchActive } from './dashboard-search-mode';
import { getWorkspaceSession } from './workspace/session';

const replaceInnerHtml = (elementId, html) => {
    const element = document.getElementById(elementId);

    if (!element || html === undefined) {
        return;
    }

    element.innerHTML = html;
};

const splitOperationalKpiStripHtml = (kpiStripHtml) => {
    if (!kpiStripHtml) {
        return { operationalHtml: kpiStripHtml, adminKpis: null };
    }

    const template = document.createElement('template');
    template.innerHTML = kpiStripHtml.trim();

    const strip = template.content.querySelector('.dashboard-kpi-strip');

    if (!strip) {
        return { operationalHtml: kpiStripHtml, adminKpis: null };
    }

    const totalUsersItem = strip.querySelector('.dashboard-kpi-item--total-users');
    const onlineUsersItem = strip.querySelector('.dashboard-kpi-item--online-users');

    if (!totalUsersItem && !onlineUsersItem) {
        return { operationalHtml: kpiStripHtml, adminKpis: null };
    }

    const adminKpis = {
        totalUsers: totalUsersItem?.outerHTML ?? null,
        onlineUsers: onlineUsersItem?.outerHTML ?? null,
    };

    totalUsersItem?.remove();
    onlineUsersItem?.remove();

    return {
        operationalHtml: strip.outerHTML,
        adminKpis,
    };
};

const applyAdminUserKpis = (adminKpis) => {
    if (!adminKpis) {
        return;
    }

    const totalUsersSlot = document.querySelector('[data-admin-kpi-slot="total-users"]');
    const onlineUsersSlot = document.querySelector('[data-admin-kpi-slot="online-users"]');

    if (adminKpis.totalUsers && totalUsersSlot) {
        totalUsersSlot.innerHTML = adminKpis.totalUsers;
    }

    if (adminKpis.onlineUsers && onlineUsersSlot) {
        onlineUsersSlot.innerHTML = adminKpis.onlineUsers;
    }

    if (adminKpis.totalUsers || adminKpis.onlineUsers) {
        initTooltips(document.querySelector('.dashboard-admin-metrics') ?? document);
    }
};

let refreshInFlight = false;
let pendingDashboardRefresh = null;
let dashboardRefreshHooks = {};

const applyFilterCounts = (counts) => {
    if (!counts || typeof counts !== 'object') {
        return;
    }

    Object.entries(counts).forEach(([filterKey, count]) => {
        const countElement = document.querySelector(
            `[data-dashboard-case-filter-count="${filterKey}"]`,
        );

        if (countElement) {
            countElement.textContent = `(${count})`;
        }
    });
};

const applyKpis = (kpiStripHtml) => {
    if (kpiStripHtml === undefined) {
        return;
    }

    const { operationalHtml, adminKpis } = splitOperationalKpiStripHtml(kpiStripHtml);

    replaceInnerHtml('dashboard-kpi-strip', operationalHtml);
    initTooltips(document.getElementById('dashboard-kpi-strip') ?? document);
    applyAdminUserKpis(adminKpis);
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
        if (isDashboardSearchActive()) {
            resolve();

            return;
        }

        if (getWorkspaceSession().isActive()) {
            queueDashboardRefresh(data);
            resolve();

            return;
        }

        applyKpis(data.kpi_strip_html);
        applyFilterCounts(data.service_case_filter_counts);

        applyRows(data.rows ?? [], {
            serviceCasesEmpty: data.service_cases_empty,
            serviceCasesEmptyHtml: data.service_cases_empty_html,
        });

        resolve();
    });
});

const applyPartialDashboardUpdate = (data) => new Promise((resolve) => {
    requestAnimationFrame(() => {
        if (isDashboardSearchActive()) {
            resolve();

            return;
        }

        if (getWorkspaceSession().isActive()) {
            queueDashboardRefresh({
                kpi_strip_html: data.kpi_strip_html,
                service_case_filter_counts: data.service_case_filter_counts,
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
    const view = pageRoot.dataset.liveView ?? 'all';

    if (!liveUrl || document.hidden || refreshInFlight || isDashboardSearchActive()) {
        return;
    }

    refreshInFlight = true;

    try {
        const query = new URLSearchParams({ filter });

        if (view && view !== 'all') {
            query.set('view', view);
        }

        const response = await fetch(`${liveUrl}?${query.toString()}`, {
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
    applyFilterCounts,
    applyKpis,
    applyPartialDashboardUpdate,
    applyRows,
    flushPendingDashboardRefresh,
    queueDashboardRefresh,
    refreshDashboard,
    startPolling,
    stopPolling,
};
