import { mergeServiceCaseRows, patchServiceCaseRows } from './live-dashboard-merge';
import { initTooltips } from './tooltips';
import { isDashboardSearchActive } from './dashboard-search-mode';
import { getWorkspaceSession } from './workspace/session';
import { isDashboardQuickFilterActive, setServiceCasePagination } from './dashboard-service-case-state';
import { buildDashboardLiveQuery } from './dashboard-live-query';
import { applyAdminKpiSlotDom, applyKpiStripDom } from './dashboard-kpi-dom';
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
import { clearReadyQueueMembershipMemory } from './ready-queue-membership-memory';

const uniqueIncidentIds = (incidentIds = []) => Array.from(new Set(
    incidentIds
        .map((id) => Number(id))
        .filter((id) => Number.isFinite(id) && id > 0),
));

const upsertPatchRowsByIncidentId = (previousRows = [], nextRows = []) => {
    const byId = new Map();

    previousRows.forEach((row) => {
        const incidentId = Number(row?.incident_id);

        if (Number.isFinite(incidentId) && incidentId > 0) {
            byId.set(incidentId, row);
        }
    });

    nextRows.forEach((row) => {
        const incidentId = Number(row?.incident_id);

        if (Number.isFinite(incidentId) && incidentId > 0) {
            byId.set(incidentId, row);
        }
    });

    return Array.from(byId.values());
};

const isAuthoritativeRefreshPayload = (data) => {
    if (!data || typeof data !== 'object') {
        return false;
    }

    if (data.authoritative === true || data.service_cases_empty === true) {
        return true;
    }

    // Explicit partials never become snapshots.
    if (data.authoritative === false || data.partial === true) {
        return false;
    }

    // Legacy full-refresh queues set rows without a partial marker.
    return Array.isArray(data.rows) && data.patch_rows === undefined;
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

    const totalResult = applyAdminKpiSlotDom(
        '[data-admin-kpi-slot="total-users"]',
        adminKpis.totalUsers,
    );
    const onlineResult = applyAdminKpiSlotDom(
        '[data-admin-kpi-slot="online-users"]',
        adminKpis.onlineUsers,
    );

    if ([totalResult, onlineResult].some((result) => result === 'patched' || result === 'replaced')) {
        initTooltips(document.querySelector('.dashboard-admin-metrics') ?? document);
    }
};

let refreshInFlight = false;
let pendingDashboardRefresh = null;
/** @type {{ pageRoot: HTMLElement, source: string, options: Record<string, unknown> } | null} */
let pendingLiveRefresh = null;
let dashboardRefreshHooks = {};

const syncRefreshLifecycleState = (pageRoot) => {
    const session = getWorkspaceSession();

    setRefreshLifecycleState({
        refreshInFlight,
        pendingDashboardRefresh: pendingDashboardRefresh !== null,
        pendingLiveRefresh: pendingLiveRefresh !== null,
        workspaceSessionActive: session.isActive(),
        workspaceActiveReasons: session.getActiveReasons(),
    });

    return pageRoot;
};

/**
 * Coalesce concurrent refreshDashboard calls that hit refreshInFlight.
 * Prefer a full refresh when either side needs rows; otherwise keep kpisOnly.
 */
const coalescePendingLiveRefresh = (previous, next) => {
    if (!previous) {
        return next;
    }

    const previousKpisOnly = previous.options?.kpisOnly === true;
    const nextKpisOnly = next.options?.kpisOnly === true;

    return {
        pageRoot: next.pageRoot ?? previous.pageRoot,
        source: next.source || previous.source,
        options: {
            ...previous.options,
            ...next.options,
            kpisOnly: previousKpisOnly && nextKpisOnly,
            force: Boolean(previous.options?.force || next.options?.force),
            resetPagination: Boolean(previous.options?.resetPagination || next.options?.resetPagination),
        },
    };
};

const toIsoTimestamp = (epochMs) => new Date(epochMs).toISOString();

const applyFilterCounts = (counts, options = {}) => {
    if (!counts || typeof counts !== 'object') {
        return;
    }

    // Absolute action_required counts (full live / kpis_only / DashboardKpisUpdated)
    // invalidate optimistic membership memory. Optimistic ±1 uses { optimistic: true }.
    if (!options.optimistic
        && Object.prototype.hasOwnProperty.call(counts, 'action_required')) {
        clearReadyQueueMembershipMemory();
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

        const nextLabel = isAgentCompact ? String(count) : `(${count})`;

        if (countElement.textContent !== nextLabel) {
            countElement.textContent = nextLabel;
        }

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

const readFilterCount = (filterKey) => {
    const countElement = document.querySelector(
        `[data-dashboard-case-filter-count="${filterKey}"]`,
    );

    if (!countElement) {
        return null;
    }

    const parsed = Number(String(countElement.textContent ?? '').replace(/[()]/g, '').trim());

    return Number.isFinite(parsed) ? parsed : null;
};

const adjustFilterCount = (filterKey, delta) => {
    if (!filterKey || !delta) {
        return null;
    }

    const current = readFilterCount(filterKey);

    if (current === null) {
        return null;
    }

    const next = Math.max(0, current + delta);
    applyFilterCounts({ [filterKey]: next }, { optimistic: true });

    return next;
};

const applyKpis = (kpiStripHtml) => {
    if (kpiStripHtml === undefined) {
        return;
    }

    const { operationalHtml, adminKpis } = splitOperationalKpiStripHtml(kpiStripHtml);
    const applyResult = applyKpiStripDom('dashboard-kpi-strip', operationalHtml);

    if (applyResult === 'patched' || applyResult === 'replaced') {
        initTooltips(document.getElementById('dashboard-kpi-strip') ?? document);
    }

    applyAdminUserKpis(adminKpis);

    return applyResult;
};

const applyRows = (rows, options = {}) => {
    const card = document.querySelector('.dashboard-service-cases-card');

    if (!card || rows === undefined) {
        return [];
    }

    const lockedIncidentIds = options.lockedIncidentIds
        ?? getWorkspaceSession().getLockedIncidentIds();

    const replacedIncidentIds = [];
    const onRowsUpdated = (ids) => {
        replacedIncidentIds.push(...ids);
        dashboardRefreshHooks.onRowsUpdated?.(ids);
    };

    if (options.mode === 'patch') {
        patchServiceCaseRows(
            card,
            rows,
            initTooltips,
            { lockedIncidentIds, onRowsUpdated },
        );

        return replacedIncidentIds;
    }

    mergeServiceCaseRows(
        card,
        rows,
        Boolean(options.serviceCasesEmpty),
        options.serviceCasesEmptyHtml ?? '',
        initTooltips,
        {
            lockedIncidentIds,
            onRowsUpdated,
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

const applyPaginationFromRefresh = (data, { hasAuthoritativeRows = false } = {}) => {
    const activeFilter = document.getElementById('dashboard-page')?.dataset.liveFilter
        ?? document.getElementById('dashboard-page')?.dataset.liveQueue
        ?? 'action_required';
    const paginationUpdate = {};

    if (hasAuthoritativeRows) {
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
            queueDashboardRefresh({
                ...data,
                authoritative: data?.authoritative !== false,
            });
            resolve();

            return;
        }

        // Non-authoritative buffered payloads must use patch semantics.
        if (!isAuthoritativeRefreshPayload(data)) {
            void applyPartialDashboardUpdate({
                ...data,
                rows: data.patch_rows ?? data.rows,
                authoritative: false,
                partial: true,
            }).then(resolve);

            return;
        }

        const hasRows = Array.isArray(data?.rows);

        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'applyDashboardRefresh_started', {
            rowCount: hasRows ? data.rows.length : null,
            hasRows,
            hasKpiStrip: data?.kpi_strip_html !== undefined,
            authoritative: true,
        });

        applyKpis(data.kpi_strip_html);
        document.dispatchEvent(new CustomEvent('dashboard:live-refresh', { detail: data }));
        applyFilterCounts(data.service_case_filter_counts);

        if (pageRoot?.dataset.operationsEmbeddedActive === '1') {
            logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'applyDashboardRefresh_skipped_rows', {
                reason: 'operations_embedded_active',
            });
            resolve();

            return;
        }

        // KPI-only payloads omit `rows`. Never coerce missing rows to [] —
        // that would delete every unlocked DOM row while leaving the badge count correct.
        if (hasRows) {
            applyRows(data.rows, {
                serviceCasesEmpty: data.service_cases_empty,
                serviceCasesEmptyHtml: data.service_cases_empty_html,
            });
        }

        const lockedIncidentIds = getWorkspaceSession().getLockedIncidentIds();

        if (data.remove_incident_ids?.length) {
            removeRows(data.remove_incident_ids, lockedIncidentIds);
        }

        if (data.patch_rows?.length) {
            applyRows(data.patch_rows, { lockedIncidentIds, mode: 'patch' });
        }

        applyPaginationFromRefresh(data, { hasAuthoritativeRows: hasRows });

        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'applyDashboardRefresh_completed', {
            rowCount: hasRows ? data.rows.length : null,
            hasRows,
        });
        resolve();
    });
});

const buildQueuedPartialRefreshPayload = (data) => {
    const payload = {
        authoritative: false,
        partial: true,
    };

    // Omit undefined keys so workspace queue merges cannot wipe earlier KPI/count
    // payloads with a later rows-only partial update.
    if (data.kpi_strip_html !== undefined) {
        payload.kpi_strip_html = data.kpi_strip_html;
    }

    if (data.service_case_filter_counts !== undefined) {
        payload.service_case_filter_counts = data.service_case_filter_counts;
    }

    // Partial row lists are patches — never authoritative snapshots.
    if (Array.isArray(data.rows) && data.rows.length > 0) {
        payload.patch_rows = data.rows;
    } else if (Array.isArray(data.patch_rows) && data.patch_rows.length > 0) {
        payload.patch_rows = data.patch_rows;
    }

    if (Array.isArray(data.remove_incident_ids) && data.remove_incident_ids.length > 0) {
        payload.remove_incident_ids = uniqueIncidentIds(data.remove_incident_ids);
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
        const patchRows = Array.isArray(data.patch_rows)
            ? data.patch_rows
            : (Array.isArray(data.rows) ? data.rows : []);

        applyKpis(data.kpi_strip_html);
        document.dispatchEvent(new CustomEvent('dashboard:live-refresh', { detail: data }));
        applyFilterCounts(data.service_case_filter_counts);

        if (data.remove_incident_ids?.length) {
            removeRows(data.remove_incident_ids, lockedIncidentIds);
        }

        if (patchRows.length > 0) {
            applyRows(patchRows, { lockedIncidentIds, mode: 'patch' });
        }

        applyPaginationFromRefresh(data, { hasAuthoritativeRows: false });

        resolve();
    });
});

const mergePendingDashboardRefresh = (previous, next) => {
    if (!previous) {
        return next;
    }

    const merged = { ...previous };
    const nextIsAuthoritative = isAuthoritativeRefreshPayload(next);

    if (next.kpi_strip_html !== undefined) {
        merged.kpi_strip_html = next.kpi_strip_html;
    }

    if (next.service_case_filter_counts !== undefined) {
        merged.service_case_filter_counts = next.service_case_filter_counts;
    }

    if (next.loaded_count !== undefined) {
        merged.loaded_count = next.loaded_count;
    }

    if (next.total_count !== undefined) {
        merged.total_count = next.total_count;
    }

    if (nextIsAuthoritative && Array.isArray(next.rows)) {
        merged.authoritative = true;
        merged.partial = false;
        merged.rows = next.rows;
        merged.service_cases_empty = next.service_cases_empty;
        merged.service_cases_empty_html = next.service_cases_empty_html;
        // Full snapshot is complete; drop stale patches/removes from earlier partials.
        delete merged.patch_rows;
        delete merged.remove_incident_ids;

        return merged;
    }

    // Partial merge: never replace an authoritative rows[] with a smaller patch.
    merged.authoritative = previous.authoritative === true;
    merged.partial = previous.partial === true || next.partial === true || next.authoritative === false;

    const nextPatchRows = Array.isArray(next.patch_rows)
        ? next.patch_rows
        : (next.authoritative === false && Array.isArray(next.rows) ? next.rows : []);

    if (nextPatchRows.length > 0) {
        if (merged.authoritative === true && Array.isArray(merged.rows)) {
            const byId = new Map(
                merged.rows.map((row) => [Number(row.incident_id), row]),
            );

            nextPatchRows.forEach((row) => {
                byId.set(Number(row.incident_id), row);
            });

            merged.rows = Array.from(byId.values());
        } else {
            merged.patch_rows = upsertPatchRowsByIncidentId(merged.patch_rows ?? [], nextPatchRows);
            merged.authoritative = false;
            merged.partial = true;
            delete merged.rows;
        }
    }

    if (Array.isArray(next.remove_incident_ids) && next.remove_incident_ids.length > 0) {
        const removeIds = uniqueIncidentIds([
            ...(merged.remove_incident_ids ?? []),
            ...next.remove_incident_ids,
        ]);

        merged.remove_incident_ids = removeIds;

        if (Array.isArray(merged.rows)) {
            merged.rows = merged.rows.filter(
                (row) => !removeIds.includes(Number(row.incident_id)),
            );
        }

        if (Array.isArray(merged.patch_rows)) {
            merged.patch_rows = merged.patch_rows.filter(
                (row) => !removeIds.includes(Number(row.incident_id)),
            );

            if (merged.patch_rows.length === 0) {
                delete merged.patch_rows;
            }
        }
    }

    return merged;
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

    if (isAuthoritativeRefreshPayload(data)) {
        await applyDashboardRefresh(data);

        return;
    }

    await applyPartialDashboardUpdate({
        ...data,
        rows: data.patch_rows ?? data.rows,
        authoritative: false,
        partial: true,
    });
};

const buildLiveRefreshQuery = (pageRoot, loadedCount = 0, options = {}) => {
    const kpisOnly = options.kpisOnly === true;

    return buildDashboardLiveQuery(pageRoot, {
        ...(kpisOnly
            ? { kpis_only: 1 }
            : { limit: loadedCount > 0 ? loadedCount : undefined }),
    });
};

const refreshDashboard = async (pageRoot, source = 'unknown', options = {}) => {
    const { kpisOnly = false, force = false, resetPagination = false } = options;
    const liveUrl = pageRoot?.dataset.liveUrl;

    logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'refreshDashboard_entered', {
        source,
        kpisOnly,
        force,
        resetPagination,
        refreshInFlightBeforeEntry: refreshInFlight,
    });

    if (!liveUrl) {
        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'refreshDashboard_suppressed', {
            source,
            reason: 'missing_live_url',
        });

        return null;
    }

    if (document.hidden && !force) {
        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'refreshDashboard_suppressed', {
            source,
            reason: 'document_hidden',
        });

        return null;
    }

    if (refreshInFlight) {
        pendingLiveRefresh = coalescePendingLiveRefresh(pendingLiveRefresh, {
            pageRoot,
            source,
            options: { kpisOnly, force, resetPagination },
        });

        logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'refreshDashboard_deferred', {
            source,
            reason: 'refresh_in_flight',
            refreshInFlightBeforeEntry: true,
            deferredSource: pendingLiveRefresh.source,
            deferredKpisOnly: pendingLiveRefresh.options?.kpisOnly === true,
        });

        return null;
    }

    if (isDashboardSearchActive()) {
        if (!force) {
            logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'refreshDashboard_suppressed', {
                source,
                reason: 'dashboard_search_active',
            });

            return null;
        }
    }

    if (isDashboardQuickFilterActive()) {
        if (!force) {
            logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'refreshDashboard_suppressed', {
                source,
                reason: 'quick_filter_active',
            });

            return null;
        }
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
        const loadedCount = resetPagination
            ? 0
            : Number(
                pageRoot.querySelector('.dashboard-service-cases-card')?.dataset.serviceCasesLoaded ?? 0,
            );
        const query = buildLiveRefreshQuery(pageRoot, loadedCount, { kpisOnly });
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

            return null;
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

                return data;
            }

            await applyPartialDashboardUpdate({
                kpi_strip_html: data.kpi_strip_html,
                service_case_filter_counts: data.service_case_filter_counts,
            });

            return data;
        }

        if (getWorkspaceSession().isActive() && !force) {
            logRefreshLifecycle(syncRefreshLifecycleState(pageRoot), 'dashboard_live_response_queued', {
                source,
                reason: 'workspace_session_active',
            });
            queueDashboardRefresh({
                ...data,
                authoritative: true,
            });

            return data;
        }

        await applyDashboardRefresh({
            ...data,
            authoritative: true,
        });

        return data;
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
        return null;
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

        const deferred = pendingLiveRefresh;
        pendingLiveRefresh = null;

        if (deferred?.pageRoot) {
            logRefreshLifecycle(syncRefreshLifecycleState(deferred.pageRoot), 'refreshDashboard_deferred_flush', {
                source: deferred.source,
                kpisOnly: deferred.options?.kpisOnly === true,
                priorSource: source,
            });

            void refreshDashboard(deferred.pageRoot, deferred.source, deferred.options ?? {});
        }
    }
};

configureDashboardPolling({
    refreshDashboard,
    getWorkspaceSession,
});

export const resetLiveDashboardRefreshStateForTests = () => {
    refreshInFlight = false;
    pendingDashboardRefresh = null;
    pendingLiveRefresh = null;
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
    adjustFilterCount,
    applyDashboardRefresh,
    applyFilterCounts,
    applyKpis,
    applyPartialDashboardUpdate,
    applyRows,
    buildLiveRefreshQuery,
    flushPendingDashboardRefresh,
    queueDashboardRefresh,
    readFilterCount,
    refreshDashboard,
    startPolling,
    startFastPolling,
    startHeartbeatPolling,
    stopPolling,
    destroyPolling,
    isPollingActive,
};
