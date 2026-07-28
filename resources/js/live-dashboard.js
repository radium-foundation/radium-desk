import { mergeServiceCaseRows } from './live-dashboard-merge';
import { initTooltips } from './tooltips';
import { isDashboardSearchActive } from './dashboard-search-mode';
import { getWorkspaceSession } from './workspace/session';
import { isDashboardQuickFilterActive, setServiceCasePagination } from './dashboard-service-case-state';
import { buildDashboardLiveQuery } from './dashboard-live-query';
import {
    configureDashboardPolling,
    destroyPolling,
    isPollingActive,
    startFastPolling,
    startHeartbeatPolling,
    startPolling,
    stopPolling,
} from './live-dashboard-polling';
import {
    logRefreshLifecycle,
    setRefreshLifecycleState,
} from './dashboard-refresh-lifecycle';

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

const syncRefreshLifecycleState = (pageRoot) => {
    const session = getWorkspaceSession();

    setRefreshLifecycleState({
        refreshInFlight,
        pendingDashboardRefresh: pendingDashboardRefresh !== null,
        workspaceSessionActive: session.isActive(),
        workspaceActiveReasons: session.getActiveReasons(),
    });

    return pageRoot;
};

const toIsoTimestamp = (epochMs) => new Date(epochMs).toISOString();

const applyFilterCounts = (counts) => {
    if (!counts || typeof counts !== 'object') {
        return;
    }

    const card = document.querySelector('.dashboard-service-cases-card');
    const hideZeroCountTabs = card?.dataset.hideZeroCountQueueTabs === 'true';
    const isAgentCompact = card?.dataset.agentCompactLayout === 'true';

    Object.entries(counts).forEach(([filterKey, count]) => {
        const countElement = document.querySelector(
            `[data-dashboard-case-filter-count="${filterKey}"]`,
        );

        if (!countElement) {
            return;
        }

        countElement.textContent = isAgentCompact ? String(count) : `(${count})`;

        if (!hideZeroCountTabs) {
            return;
        }

        const tab = countElement.closest('[role="tab"]');
        const isActive = tab?.classList.contains('is-active');

        if (!tab || isActive) {
            return;
        }

        tab.classList.toggle('d-none', Number(count) === 0);
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
    const pageRoot = document.getElementById('dashboard-page');

    requestAnimationFrame(() => {
        if (isDashboardSearchActive() || isDashboardQuickFilterActive()) {
            logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'applyDashboardRefresh_suppressed', {
                reason: isDashboardSearchActive() ? 'dashboard_search_active' : 'quick_filter_active',
            });
            resolve();

            return;
        }

        if (getWorkspaceSession().isActive()) {
            logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'applyDashboardRefresh_queued', {
                reason: 'workspace_session_active',
            });
            queueDashboardRefresh(data);
            resolve();

            return;
        }

        const hasRows = Array.isArray(data?.rows);

        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'applyDashboardRefresh_started', {
            rowCount: hasRows ? data.rows.length : null,
            hasRows,
            hasKpiStrip: data?.kpi_strip_html !== undefined,
        });

        applyKpis(data.kpi_strip_html);
        document.dispatchEvent(new CustomEvent('dashboard:live-refresh', { detail: data }));
        applyFilterCounts(data.service_case_filter_counts);

        // KPI-only / partial queued payloads omit `rows`. Never coerce missing rows to [] —
        // that would delete every unlocked DOM row while leaving the badge count correct.
        if (hasRows) {
            applyRows(data.rows, {
                serviceCasesEmpty: data.service_cases_empty,
                serviceCasesEmptyHtml: data.service_cases_empty_html,
            });
        }

        const activeFilter = document.getElementById('dashboard-page')?.dataset.liveFilter
            ?? document.getElementById('dashboard-page')?.dataset.liveQueue
            ?? 'action_required';
        const paginationUpdate = {};

        if (hasRows) {
            paginationUpdate.loaded = data.loaded_count ?? data.rows.length;
        } else if (data.loaded_count !== undefined) {
            paginationUpdate.loaded = data.loaded_count;
        }

        if (data.total_count !== undefined) {
            paginationUpdate.total = data.total_count;
        } else if (data.service_case_filter_counts?.[activeFilter] !== undefined) {
            paginationUpdate.total = data.service_case_filter_counts[activeFilter];
        }

        if (Object.keys(paginationUpdate).length > 0) {
            setServiceCasePagination(paginationUpdate);
        }

        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'applyDashboardRefresh_completed', {
            rowCount: hasRows ? data.rows.length : null,
            hasRows,
        });
        resolve();
    });
});

const buildQueuedPartialRefreshPayload = (data) => {
    const payload = {
        kpi_strip_html: data.kpi_strip_html,
        service_case_filter_counts: data.service_case_filter_counts,
    };

    // Only include an authoritative row set when the caller supplied one.
    // Missing rows must stay missing so flush does not wipe the grid.
    if (Array.isArray(data.rows)) {
        payload.rows = data.rows;
        payload.service_cases_empty = data.service_cases_empty;
        payload.service_cases_empty_html = data.service_cases_empty_html;
    }

    if (data.loaded_count !== undefined) {
        payload.loaded_count = data.loaded_count;
    }

    if (data.total_count !== undefined) {
        payload.total_count = data.total_count;
    }

    return payload;
};

const applyPartialDashboardUpdate = (data) => new Promise((resolve) => {
    requestAnimationFrame(() => {
        if (isDashboardSearchActive() || isDashboardQuickFilterActive()) {
            resolve();

            return;
        }

        if (getWorkspaceSession().isActive()) {
            queueDashboardRefresh(buildQueuedPartialRefreshPayload(data));
            resolve();

            return;
        }

        const lockedIncidentIds = getWorkspaceSession().getLockedIncidentIds();

        applyKpis(data.kpi_strip_html);
        document.dispatchEvent(new CustomEvent('dashboard:live-refresh', { detail: data }));
        applyFilterCounts(data.service_case_filter_counts);

        if (data.remove_incident_ids?.length) {
            removeRows(data.remove_incident_ids, lockedIncidentIds);
        }

        if (data.rows?.length) {
            applyRows(data.rows, { lockedIncidentIds });
        }

        resolve();
    });
});

const mergePendingDashboardRefresh = (previous, next) => {
    if (!previous) {
        return next;
    }

    // Shallow merge keeps an earlier authoritative `rows` set when a later KPI-only
    // payload omits rows (last-write-wins would otherwise discard the row list).
    return {
        ...previous,
        ...next,
    };
};

const queueDashboardRefresh = (data) => {
    pendingDashboardRefresh = mergePendingDashboardRefresh(pendingDashboardRefresh, data);

    const session = getWorkspaceSession();

    setRefreshLifecycleState({
        refreshInFlight,
        pendingDashboardRefresh: true,
        workspaceSessionActive: session.isActive(),
        workspaceActiveReasons: session.getActiveReasons(),
    });
};

const flushPendingDashboardRefresh = async () => {
    if (!pendingDashboardRefresh) {
        return;
    }

    const data = pendingDashboardRefresh;
    pendingDashboardRefresh = null;
    await applyDashboardRefresh(data);
};

const buildLiveRefreshQuery = (pageRoot, loadedCount = 0) => buildDashboardLiveQuery(pageRoot, {
    limit: loadedCount > 0 ? loadedCount : undefined,
});

const refreshDashboard = async (pageRoot, source = 'unknown', options = {}) => {
    const { kpisOnly = false } = options;
    const liveUrl = pageRoot?.dataset.liveUrl;

    logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'refreshDashboard_entered', {
        source,
        kpisOnly,
        refreshInFlightBeforeEntry: refreshInFlight,
    });

    if (!liveUrl) {
        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'refreshDashboard_suppressed', {
            source,
            reason: 'missing_live_url',
        });

        return;
    }

    if (document.hidden) {
        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'refreshDashboard_suppressed', {
            source,
            reason: 'document_hidden',
        });

        return;
    }

    if (refreshInFlight) {
        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'refreshDashboard_suppressed', {
            source,
            reason: 'refresh_in_flight',
            refreshInFlightBeforeEntry: true,
        });

        return;
    }

    if (isDashboardSearchActive()) {
        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'refreshDashboard_suppressed', {
            source,
            reason: 'dashboard_search_active',
        });

        return;
    }

    if (isDashboardQuickFilterActive()) {
        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'refreshDashboard_suppressed', {
            source,
            reason: 'quick_filter_active',
        });

        return;
    }

    refreshInFlight = true;
    syncRefreshLifecycleState(pageRoot);

    const requestStartedAt = Date.now();

    logRefreshLifecycle(pageRoot, 'refreshInFlight_set', {
        source,
        value: true,
        requestStartedAt: toIsoTimestamp(requestStartedAt),
    });

    let requestUrl = null;
    let requestFinishedAt = null;

    try {
        const loadedCount = Number(
            pageRoot.querySelector('.dashboard-service-cases-card')?.dataset.serviceCasesLoaded ?? 0,
        );
        const query = buildLiveRefreshQuery(pageRoot, loadedCount);
        requestUrl = `${liveUrl}?${query.toString()}`;

        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'dashboard_live_request_started', {
            source,
            url: requestUrl,
            loadedCount,
            requestStartedAt: toIsoTimestamp(requestStartedAt),
        });

        const response = await fetch(requestUrl, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        requestFinishedAt = Date.now();

        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'dashboard_live_response_received', {
            source,
            status: response.status,
            ok: response.ok,
            requestStartedAt: toIsoTimestamp(requestStartedAt),
            requestFinishedAt: toIsoTimestamp(requestFinishedAt),
            durationMs: requestFinishedAt - requestStartedAt,
        });

        if (!response.ok) {
            logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'dashboard_live_response_ignored', {
                source,
                reason: 'response_not_ok',
                status: response.status,
                requestFinishedAt: toIsoTimestamp(requestFinishedAt),
                durationMs: requestFinishedAt - requestStartedAt,
            });

            return;
        }

        const data = await response.json();

        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'dashboard_live_response_parsed', {
            source,
            kpisOnly,
            rowCount: Array.isArray(data.rows) ? data.rows.length : 0,
            hasKpiStrip: data.kpi_strip_html !== undefined,
            requestFinishedAt: toIsoTimestamp(requestFinishedAt),
            durationMs: requestFinishedAt - requestStartedAt,
        });

        if (kpisOnly) {
            if (getWorkspaceSession().isActive()) {
                logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'dashboard_live_response_queued', {
                    source,
                    reason: 'workspace_session_active',
                    kpisOnly: true,
                });
                queueDashboardRefresh({
                    kpi_strip_html: data.kpi_strip_html,
                    service_case_filter_counts: data.service_case_filter_counts,
                });

                return;
            }

            await applyPartialDashboardUpdate({
                kpi_strip_html: data.kpi_strip_html,
                service_case_filter_counts: data.service_case_filter_counts,
            });

            return;
        }

        if (getWorkspaceSession().isActive()) {
            logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'dashboard_live_response_queued', {
                source,
                reason: 'workspace_session_active',
            });
            queueDashboardRefresh(data);

            return;
        }

        await applyDashboardRefresh(data);
    } catch (error) {
        requestFinishedAt = Date.now();

        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'dashboard_live_request_failed', {
            source,
            requestStartedAt: toIsoTimestamp(requestStartedAt),
            requestFinishedAt: toIsoTimestamp(requestFinishedAt),
            durationMs: requestFinishedAt - requestStartedAt,
            errorName: error?.name ?? null,
            errorMessage: error?.message ?? String(error),
            url: requestUrl,
        });
        // Ignore transient network errors during background refresh.
    } finally {
        refreshInFlight = false;
        requestFinishedAt = requestFinishedAt ?? Date.now();

        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'refreshInFlight_cleared', {
            source,
            value: false,
            requestFinishedAt: toIsoTimestamp(requestFinishedAt),
            durationMs: requestFinishedAt - requestStartedAt,
        });
        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'refreshDashboard_exited', {
            source,
            requestStartedAt: toIsoTimestamp(requestStartedAt),
            requestFinishedAt: toIsoTimestamp(requestFinishedAt),
            durationMs: requestFinishedAt - requestStartedAt,
        });
    }
};

configureDashboardPolling({
    refreshDashboard,
    getWorkspaceSession,
});

export const resetLiveDashboardRefreshStateForTests = () => {
    refreshInFlight = false;
    pendingDashboardRefresh = null;
};

export const configureLiveDashboard = (hooks = {}) => {
    dashboardRefreshHooks = hooks;
};

export const initLiveDashboard = (hooks = {}) => {
    const pageRoot = document.getElementById('dashboard-page');

    if (!pageRoot?.dataset.liveUrl) {
        return {
            startPolling,
            startFastPolling,
            startHeartbeatPolling,
            stopPolling,
            pageRoot: null,
        };
    }

    configureLiveDashboard(hooks);
    const session = getWorkspaceSession();

    session.onIdle(() => {
        flushPendingDashboardRefresh();
    });

    const liveUpdatesEnabled = pageRoot.dataset.liveUpdatesEnabled !== '0';
    const liveMode = pageRoot.dataset.liveMode ?? 'poll';

    if (liveUpdatesEnabled && liveMode === 'poll') {
        startPolling(pageRoot);
    }

    return {
        startPolling: () => {
            if (pageRoot.dataset.liveUpdatesEnabled === '0') {
                return;
            }

            startPolling(pageRoot);
        },
        startFastPolling: () => {
            if (pageRoot.dataset.liveUpdatesEnabled === '0') {
                return;
            }

            startFastPolling(pageRoot);
        },
        startHeartbeatPolling: () => {
            if (pageRoot.dataset.liveUpdatesEnabled === '0') {
                return;
            }

            startHeartbeatPolling(pageRoot);
        },
        stopPolling,
        destroyPolling,
        isPollingActive,
        pageRoot,
    };
};

export {
    applyDashboardRefresh,
    applyFilterCounts,
    applyKpis,
    applyPartialDashboardUpdate,
    applyRows,
    buildLiveRefreshQuery,
    flushPendingDashboardRefresh,
    queueDashboardRefresh,
    refreshDashboard,
    startPolling,
    startFastPolling,
    startHeartbeatPolling,
    stopPolling,
    destroyPolling,
    isPollingActive,
};
