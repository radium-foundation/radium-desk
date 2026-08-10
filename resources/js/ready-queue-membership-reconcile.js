import { buildDashboardLiveQuery } from './dashboard-live-query';
import { isDashboardSearchActive } from './dashboard-search-mode';
import { isDashboardQuickFilterActive, setServiceCasePagination } from './dashboard-service-case-state';
import {
    buildDashboardEmptyStateHtml,
    DASHBOARD_EMPTY_VARIANT,
    getTableColumnCount,
    syncDashboardTableEmptyPresentation,
} from './dashboard-empty-state';
import { logRefreshLifecycle } from './dashboard-refresh-lifecycle';
import { fetchLiveRowsForIncidents } from './live-dashboard-reverb';
import { applyPartialDashboardUpdate } from './live-dashboard';
import { patchServiceCaseRows } from './live-dashboard-merge';
import { initTooltips } from './tooltips';
import { isViewingReadyQueue } from './ready-queue-count-delta';
import { getWorkspaceSession } from './workspace/session';

/** Matches config dashboard.service_cases_page_size default — automatic visible window cap. */
export const AUTOMATIC_MEMBERSHIP_WINDOW_MAX = 35;

let membershipReconcileInFlight = false;

export const resetReadyQueueMembershipReconcileForTests = () => {
    membershipReconcileInFlight = false;
};

const normalizeIncidentIds = (ids) => (Array.isArray(ids) ? ids : [])
    .map((id) => Number(id))
    .filter((id) => Number.isFinite(id) && id > 0);

export const arraysEqual = (left, right) => {
    if (left.length !== right.length) {
        return false;
    }

    return left.every((value, index) => value === right[index]);
};

/**
 * Automatic heartbeat window: min(total, 35).
 */
export const computeAutomaticWindowSize = (totalCount, maxWindow = AUTOMATIC_MEMBERSHIP_WINDOW_MAX) => (
    Math.max(0, Math.min(maxWindow, Number(totalCount) || 0))
);

const getServiceCasesCard = (pageRoot) => (
    pageRoot?.querySelector('.dashboard-service-cases-card')
    ?? document.querySelector('.dashboard-service-cases-card')
);

/**
 * First N service-case row IDs in DOM order (automatic visible window slice).
 */
export const getDomAutomaticWindowIds = (card, windowSize) => {
    const tbody = card?.querySelector('#dashboard-service-cases-body');

    if (!tbody || windowSize <= 0) {
        return [];
    }

    return Array.from(tbody.querySelectorAll('tr[id^="service-case-row-"]'))
        .slice(0, windowSize)
        .map((row) => Number(row.id.replace('service-case-row-', '')))
        .filter((id) => Number.isFinite(id) && id > 0);
};

/**
 * @returns {{
 *   unchanged: boolean,
 *   removeIds: number[],
 *   addIds: number[],
 *   reorderOnly: boolean,
 *   serverWindow: number[],
 * }}
 */
export const computeMembershipDiff = (serverIds, domWindowIds) => {
    const serverWindow = normalizeIncidentIds(serverIds);
    const domWindow = normalizeIncidentIds(domWindowIds);

    if (arraysEqual(serverWindow, domWindow)) {
        return {
            unchanged: true,
            removeIds: [],
            addIds: [],
            reorderOnly: false,
            serverWindow,
        };
    }

    const serverSet = new Set(serverWindow);
    const domSet = new Set(domWindow);
    const removeIds = domWindow.filter((id) => !serverSet.has(id));
    const addIds = serverWindow.filter((id) => !domSet.has(id));
    const reorderOnly = removeIds.length === 0 && addIds.length === 0;

    return {
        unchanged: false,
        removeIds,
        addIds,
        reorderOnly,
        serverWindow,
    };
};

const removeRowsById = (incidentIds, lockedIncidentIds) => {
    incidentIds.forEach((incidentId) => {
        if (lockedIncidentIds.includes(Number(incidentId))) {
            return;
        }

        document.getElementById(`service-case-row-${incidentId}`)?.remove();
    });
};

/**
 * Reorder the automatic window rows to match server order; preserve load-more tail below.
 */
export const reorderAutomaticWindow = (card, serverWindow) => {
    const tbody = card?.querySelector('#dashboard-service-cases-body');

    if (!tbody || serverWindow.length === 0) {
        return;
    }

    const allRows = Array.from(tbody.querySelectorAll('tr[id^="service-case-row-"]'));
    const serverSet = new Set(serverWindow);
    const tailRows = allRows.filter((row) => {
        const id = Number(row.id.replace('service-case-row-', ''));

        return Number.isFinite(id) && !serverSet.has(id);
    });

    serverWindow.forEach((incidentId) => {
        const row = document.getElementById(`service-case-row-${incidentId}`);

        if (row) {
            tbody.appendChild(row);
        }
    });

    tailRows.forEach((row) => {
        tbody.appendChild(row);
    });
};

const showCaughtUpEmptyState = (card) => {
    const tbody = card?.querySelector('#dashboard-service-cases-body');
    const scrollContainer = card?.querySelector('#dashboard-service-cases-scroll');

    if (!tbody) {
        return;
    }

    const previousScrollTop = scrollContainer?.scrollTop ?? 0;
    const colSpan = getTableColumnCount(tbody);

    tbody.innerHTML = buildDashboardEmptyStateHtml({
        variant: DASHBOARD_EMPTY_VARIANT.CAUGHT_UP,
        colSpan,
    });

    syncDashboardTableEmptyPresentation(card);

    if (scrollContainer) {
        scrollContainer.scrollTop = previousScrollTop;
    }

    initTooltips(tbody);
};

const shouldSkipMembershipReconcile = (pageRoot) => (
    !pageRoot?.dataset.liveUrl
    || document.hidden
    || !isViewingReadyQueue(pageRoot)
    || isDashboardSearchActive()
    || isDashboardQuickFilterActive()
);

export const reconcileReadyQueueMembership = async (pageRoot, source = 'membership_heartbeat') => {
    if (shouldSkipMembershipReconcile(pageRoot)) {
        logRefreshLifecycle(pageRoot, 'membership_reconcile_suppressed', {
            source,
            reason: 'guards',
            hidden: document.hidden,
            viewingReady: isViewingReadyQueue(pageRoot),
            searchActive: isDashboardSearchActive(),
            quickFilterActive: isDashboardQuickFilterActive(),
        });

        return null;
    }

    if (membershipReconcileInFlight) {
        logRefreshLifecycle(pageRoot, 'membership_reconcile_suppressed', {
            source,
            reason: 'in_flight',
        });

        return null;
    }

    const liveUrl = pageRoot.dataset.liveUrl;
    const card = getServiceCasesCard(pageRoot);

    if (!liveUrl || !card) {
        return null;
    }

    membershipReconcileInFlight = true;

    const requestStartedAt = Date.now();

    try {
        const query = buildDashboardLiveQuery(pageRoot, {
            kpis_only: 1,
            membership: 1,
        });
        const requestUrl = `${liveUrl}?${query.toString()}`;

        logRefreshLifecycle(pageRoot, 'membership_reconcile_started', {
            source,
            requestUrl,
        });

        const response = await fetch(requestUrl, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            logRefreshLifecycle(pageRoot, 'membership_reconcile_failed', {
                source,
                status: response.status,
            });

            return null;
        }

        const data = await response.json();
        const liveScope = pageRoot.dataset.liveScope ?? 'operations_scope';
        const filterCounts = data.service_case_filter_count_variants?.[liveScope]
            ?? data.service_case_filter_counts;
        const totalCount = Number(data.total_count ?? filterCounts?.action_required ?? 0);
        const serverWindow = normalizeIncidentIds(data.incident_ids);
        const windowSize = computeAutomaticWindowSize(totalCount);
        const domSliceLimit = windowSize > 0 ? windowSize : AUTOMATIC_MEMBERSHIP_WINDOW_MAX;
        const domWindow = getDomAutomaticWindowIds(card, domSliceLimit);
        const diff = computeMembershipDiff(serverWindow, domWindow);

        if (getWorkspaceSession().isActive()) {
            await applyPartialDashboardUpdate({
                kpi_strip_html: data.kpi_strip_html,
                service_case_filter_counts: filterCounts,
                total_count: totalCount,
            });

            logRefreshLifecycle(pageRoot, 'membership_reconcile_queued', {
                source,
                reason: 'workspace_session_active',
            });

            return data;
        }

        await applyPartialDashboardUpdate({
            kpi_strip_html: data.kpi_strip_html,
            service_case_filter_counts: filterCounts,
            total_count: totalCount,
        });
        setServiceCasePagination({ total: totalCount });

        if (diff.unchanged) {
            logRefreshLifecycle(pageRoot, 'membership_reconcile_completed', {
                source,
                totalCount,
                windowSize,
                rowFetches: 0,
                durationMs: Date.now() - requestStartedAt,
                rowsChanged: false,
            });

            return data;
        }

        const lockedIncidentIds = getWorkspaceSession().getLockedIncidentIds();

        if (serverWindow.length === 0) {
            removeRowsById(domWindow, lockedIncidentIds);
            showCaughtUpEmptyState(card);

            logRefreshLifecycle(pageRoot, 'membership_reconcile_completed', {
                source,
                totalCount: 0,
                windowSize: 0,
                rowFetches: 0,
                durationMs: Date.now() - requestStartedAt,
                rowsChanged: domWindow.length > 0,
            });

            return data;
        }

        let rows = [];

        if (diff.addIds.length > 0) {
            const fetched = await fetchLiveRowsForIncidents(pageRoot, diff.addIds);
            rows = fetched.rows ?? [];
        }

        if (diff.removeIds.length > 0) {
            removeRowsById(diff.removeIds, lockedIncidentIds);
        }

        if (rows.length > 0) {
            patchServiceCaseRows(card, rows, initTooltips, { lockedIncidentIds });
        }

        if (diff.reorderOnly || diff.removeIds.length > 0 || diff.addIds.length > 0) {
            reorderAutomaticWindow(card, serverWindow);
        }

        syncDashboardTableEmptyPresentation(card);

        logRefreshLifecycle(pageRoot, 'membership_reconcile_completed', {
            source,
            totalCount,
            windowSize,
            rowFetches: diff.addIds.length,
            removeCount: diff.removeIds.length,
            reorderOnly: diff.reorderOnly,
            durationMs: Date.now() - requestStartedAt,
            rowsChanged: true,
        });

        return data;
    } catch (error) {
        logRefreshLifecycle(pageRoot, 'membership_reconcile_failed', {
            source,
            errorMessage: error?.message ?? String(error),
        });

        return null;
    } finally {
        membershipReconcileInFlight = false;
    }
};
