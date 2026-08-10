import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    AUTOMATIC_MEMBERSHIP_WINDOW_MAX,
    arraysEqual,
    computeAutomaticWindowSize,
    computeMembershipDiff,
    getDomAutomaticWindowIds,
    reconcileReadyQueueMembership,
    reorderAutomaticWindow,
    resetReadyQueueMembershipReconcileForTests,
} from '../../resources/js/ready-queue-membership-reconcile';
import { resetDashboardSearchMode, setDashboardSearchActive } from '../../resources/js/dashboard-search-mode';
import {
    initServiceCasePaginationState,
    setServiceCasePagination,
    setServiceCaseSearchQuery,
} from '../../resources/js/dashboard-service-case-state';

const fetchLiveRowsForIncidents = vi.hoisted(() => vi.fn());
const applyPartialDashboardUpdate = vi.hoisted(() => vi.fn().mockResolvedValue(undefined));

vi.mock('../../resources/js/live-dashboard-reverb', () => ({
    fetchLiveRowsForIncidents,
}));

vi.mock('../../resources/js/live-dashboard', () => ({
    applyPartialDashboardUpdate,
}));

vi.mock('../../resources/js/workspace/session', () => ({
    getWorkspaceSession: () => ({
        isActive: () => false,
        getLockedIncidentIds: () => [],
    }),
}));

const buildRowHtml = (id) => `<tr id="service-case-row-${id}"><td>SC${id}</td></tr>`;

const buildDashboardDom = ({
    rowIds = [],
    tailIds = [],
    actionRequiredCount = rowIds.length,
    loaded = null,
    total = null,
} = {}) => {
    const automaticRows = rowIds.map((id) => buildRowHtml(id)).join('');
    const tailRows = tailIds.map((id) => buildRowHtml(id)).join('');

    document.body.innerHTML = `
        <div id="dashboard-page"
             data-live-url="/dashboard/live"
             data-live-queue="action_required"
             data-live-scope="operations_scope"></div>
        <div class="dashboard-service-cases-card"
             data-service-cases-loaded="${loaded ?? rowIds.length + tailIds.length}"
             data-service-case-filter-total="${total ?? actionRequiredCount}">
            <span data-dashboard-case-filter-count="action_required">(${actionRequiredCount})</span>
            <div id="dashboard-service-cases-scroll">
                <table>
                    <tbody id="dashboard-service-cases-body">
                        ${automaticRows}${tailRows}
                    </tbody>
                </table>
            </div>
            <div data-dashboard-load-more-wrap class="d-none"></div>
        </div>
    `;

    initServiceCasePaginationState(document);
};

const mockMembershipResponse = ({
    incidentIds = [],
    totalCount = incidentIds.length,
    actionRequired = totalCount,
} = {}) => {
    const payload = {
        kpi_strip_html: '<div data-kpi-strip>kpi</div>',
        service_case_filter_counts: { action_required: actionRequired },
        service_case_filter_count_variants: {
            operations_scope: { action_required: actionRequired },
        },
        incident_ids: incidentIds,
        total_count: totalCount,
        loaded_count: Math.min(incidentIds.length, AUTOMATIC_MEMBERSHIP_WINDOW_MAX),
        has_more: totalCount > incidentIds.length,
        membership: true,
        rows: [],
    };

    global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: async () => payload,
    });

    return payload;
};

describe('ready queue membership reconcile — pure helpers', () => {
    it('computes automatic window size capped at 35', () => {
        expect(computeAutomaticWindowSize(0)).toBe(0);
        expect(computeAutomaticWindowSize(2)).toBe(2);
        expect(computeAutomaticWindowSize(35)).toBe(35);
        expect(computeAutomaticWindowSize(75)).toBe(35);
    });

    it('detects unchanged membership', () => {
        const diff = computeMembershipDiff([1, 2, 3], [1, 2, 3]);

        expect(diff).toMatchObject({
            unchanged: true,
            removeIds: [],
            addIds: [],
            reorderOnly: false,
        });
    });

    it('detects reorder-only diff', () => {
        const diff = computeMembershipDiff([3, 1, 2], [1, 2, 3]);

        expect(diff).toMatchObject({
            unchanged: false,
            removeIds: [],
            addIds: [],
            reorderOnly: true,
        });
    });

    it('detects targeted add and remove IDs', () => {
        const diff = computeMembershipDiff([2, 3, 99], [1, 2, 3]);

        expect(diff.removeIds).toEqual([1]);
        expect(diff.addIds).toEqual([99]);
        expect(diff.reorderOnly).toBe(false);
    });
});

describe('ready queue membership reconcile — heartbeat handler', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        resetReadyQueueMembershipReconcileForTests();
        resetDashboardSearchMode();
        setServiceCaseSearchQuery('');
        fetchLiveRowsForIncidents.mockReset();
        applyPartialDashboardUpdate.mockClear();
        fetchLiveRowsForIncidents.mockImplementation(async (_pageRoot, ids) => ({
            rows: ids.map((incident_id) => ({
                incident_id,
                html: buildRowHtml(incident_id),
            })),
        }));
    });

    afterEach(() => {
        resetReadyQueueMembershipReconcileForTests();
        resetDashboardSearchMode();
        setServiceCaseSearchQuery('');
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('1→2 growth fetches only the newly required row', async () => {
        buildDashboardDom({ rowIds: [1], actionRequiredCount: 1 });
        mockMembershipResponse({ incidentIds: [1, 2], totalCount: 2, actionRequired: 2 });

        const pageRoot = document.getElementById('dashboard-page');
        await reconcileReadyQueueMembership(pageRoot);

        expect(fetchLiveRowsForIncidents).toHaveBeenCalledTimes(1);
        expect(fetchLiveRowsForIncidents).toHaveBeenCalledWith(pageRoot, [2]);
        expect(document.getElementById('service-case-row-2')).not.toBeNull();
    });

    it('35→75 with unchanged top 35 performs zero row fetches', async () => {
        const top35 = Array.from({ length: 35 }, (_, index) => index + 1);
        buildDashboardDom({ rowIds: top35, actionRequiredCount: 35 });
        mockMembershipResponse({
            incidentIds: top35,
            totalCount: 75,
            actionRequired: 75,
        });

        const pageRoot = document.getElementById('dashboard-page');
        await reconcileReadyQueueMembership(pageRoot);

        expect(fetchLiveRowsForIncidents).not.toHaveBeenCalled();
        expect(applyPartialDashboardUpdate).toHaveBeenCalled();
        expect(getDomAutomaticWindowIds(
            document.querySelector('.dashboard-service-cases-card'),
            35,
        )).toEqual(top35);
    });

    it('visible case leaving (assigned away) fetches only the replacement row', async () => {
        const domIds = Array.from({ length: 35 }, (_, index) => index + 1);
        const serverIds = [...domIds.slice(1), 36];

        buildDashboardDom({ rowIds: domIds, actionRequiredCount: 35 });
        mockMembershipResponse({
            incidentIds: serverIds,
            totalCount: 36,
            actionRequired: 36,
        });

        const pageRoot = document.getElementById('dashboard-page');
        await reconcileReadyQueueMembership(pageRoot);

        expect(document.getElementById('service-case-row-1')).toBeNull();
        expect(fetchLiveRowsForIncidents).toHaveBeenCalledTimes(1);
        expect(fetchLiveRowsForIncidents).toHaveBeenCalledWith(pageRoot, [36]);
        expect(document.getElementById('service-case-row-36')).not.toBeNull();
    });

    it('visible case leaving after service reference added fetches only replacement', async () => {
        const domIds = [10, 11, 12];
        const serverIds = [11, 12, 99];

        buildDashboardDom({ rowIds: domIds, actionRequiredCount: 3 });
        mockMembershipResponse({
            incidentIds: serverIds,
            totalCount: 4,
            actionRequired: 4,
        });

        const pageRoot = document.getElementById('dashboard-page');
        await reconcileReadyQueueMembership(pageRoot);

        expect(document.getElementById('service-case-row-10')).toBeNull();
        expect(fetchLiveRowsForIncidents).toHaveBeenCalledWith(pageRoot, [99]);
    });

    it('multiple visible cases leaving fetches only replacement IDs', async () => {
        const domIds = [1, 2, 3, 4, 5];
        const serverIds = [3, 4, 5, 101, 102];

        buildDashboardDom({ rowIds: domIds, actionRequiredCount: 5 });
        mockMembershipResponse({
            incidentIds: serverIds,
            totalCount: 7,
            actionRequired: 7,
        });

        const pageRoot = document.getElementById('dashboard-page');
        await reconcileReadyQueueMembership(pageRoot);

        expect(fetchLiveRowsForIncidents).toHaveBeenCalledTimes(1);
        expect(fetchLiveRowsForIncidents).toHaveBeenCalledWith(pageRoot, [101, 102]);
        expect(document.getElementById('service-case-row-1')).toBeNull();
        expect(document.getElementById('service-case-row-2')).toBeNull();
        expect(document.getElementById('service-case-row-101')).not.toBeNull();
        expect(document.getElementById('service-case-row-102')).not.toBeNull();
    });

    it('new case entering at position <=35 reconciles visible window only', async () => {
        buildDashboardDom({ rowIds: [2, 3], actionRequiredCount: 2 });
        mockMembershipResponse({
            incidentIds: [1, 2, 3],
            totalCount: 3,
            actionRequired: 3,
        });

        const pageRoot = document.getElementById('dashboard-page');
        await reconcileReadyQueueMembership(pageRoot);

        expect(fetchLiveRowsForIncidents).toHaveBeenCalledWith(pageRoot, [1]);
        expect(getDomAutomaticWindowIds(
            document.querySelector('.dashboard-service-cases-card'),
            3,
        )).toEqual([1, 2, 3]);
    });

    it('new case entering at position >35 updates badge only with zero row work', async () => {
        const top35 = Array.from({ length: 35 }, (_, index) => index + 1);
        buildDashboardDom({ rowIds: top35, actionRequiredCount: 35 });
        mockMembershipResponse({
            incidentIds: top35,
            totalCount: 36,
            actionRequired: 36,
        });

        const pageRoot = document.getElementById('dashboard-page');
        await reconcileReadyQueueMembership(pageRoot);

        expect(fetchLiveRowsForIncidents).not.toHaveBeenCalled();
        expect(applyPartialDashboardUpdate).toHaveBeenCalled();
    });

    it('top-35 reorder with same IDs reorders DOM only', async () => {
        const ids = [1, 2, 3];
        buildDashboardDom({ rowIds: ids, actionRequiredCount: 3 });
        mockMembershipResponse({
            incidentIds: [3, 1, 2],
            totalCount: 3,
            actionRequired: 3,
        });

        const pageRoot = document.getElementById('dashboard-page');
        const card = document.querySelector('.dashboard-service-cases-card');

        await reconcileReadyQueueMembership(pageRoot);

        expect(fetchLiveRowsForIncidents).not.toHaveBeenCalled();
        expect(getDomAutomaticWindowIds(card, 3)).toEqual([3, 1, 2]);
    });

    it('empty Ready Queue removes visible rows and shows caught-up empty state', async () => {
        buildDashboardDom({ rowIds: [1, 2], actionRequiredCount: 2 });
        mockMembershipResponse({
            incidentIds: [],
            totalCount: 0,
            actionRequired: 0,
        });

        const pageRoot = document.getElementById('dashboard-page');
        await reconcileReadyQueueMembership(pageRoot);

        expect(document.querySelector('#service-case-row-1')).toBeNull();
        expect(document.querySelector('.dashboard-service-cases-empty-row')).not.toBeNull();
        expect(document.body.textContent).toContain('All caught up!');
        expect(fetchLiveRowsForIncidents).not.toHaveBeenCalled();
    });

    it('load more tail beyond 35 is preserved during automatic window reconcile', async () => {
        const top35 = Array.from({ length: 35 }, (_, index) => index + 1);
        const tail = [101, 102, 103];
        buildDashboardDom({
            rowIds: top35,
            tailIds: tail,
            actionRequiredCount: 60,
            loaded: 38,
            total: 60,
        });

        const reorderedTop = [...top35.slice(1), 36];
        mockMembershipResponse({
            incidentIds: reorderedTop,
            totalCount: 60,
            actionRequired: 60,
        });

        const pageRoot = document.getElementById('dashboard-page');
        const card = document.querySelector('.dashboard-service-cases-card');

        await reconcileReadyQueueMembership(pageRoot);

        expect(fetchLiveRowsForIncidents).toHaveBeenCalledWith(pageRoot, [36]);
        expect(document.getElementById('service-case-row-101')).not.toBeNull();
        expect(document.getElementById('service-case-row-102')).not.toBeNull();
        expect(document.getElementById('service-case-row-103')).not.toBeNull();

        const tbody = card.querySelector('#dashboard-service-cases-body');
        const domOrder = Array.from(tbody.querySelectorAll('tr[id^="service-case-row-"]'))
            .map((row) => Number(row.id.replace('service-case-row-', '')));

        expect(domOrder.slice(0, 35)).toEqual(reorderedTop);
        expect(domOrder.slice(35)).toEqual(tail);

        const cardAfter = document.querySelector('.dashboard-service-cases-card');
        expect(Number(cardAfter.dataset.serviceCasesLoaded)).toBe(38);
    });

    it('automatic window grows from 1 through 35 by fetching only new IDs each step', () => {
        let domWindow = [];

        for (let total = 1; total <= 35; total += 1) {
            const serverWindow = Array.from({ length: total }, (_, index) => index + 1);
            const diff = computeMembershipDiff(serverWindow, domWindow);

            expect(diff.removeIds).toEqual([]);
            expect(diff.addIds).toEqual([total]);
            domWindow = serverWindow;
        }

        const unchanged = computeMembershipDiff(domWindow, domWindow);
        expect(unchanged.unchanged).toBe(true);
    });

    it('suppresses heartbeat when not viewing Ready Queue', async () => {
        buildDashboardDom({ rowIds: [1], actionRequiredCount: 1 });
        document.getElementById('dashboard-page').dataset.liveQueue = 'waiting_customer';
        mockMembershipResponse({ incidentIds: [1, 2], totalCount: 2 });

        const pageRoot = document.getElementById('dashboard-page');
        const result = await reconcileReadyQueueMembership(pageRoot);

        expect(result).toBeNull();
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('suppresses heartbeat while search is active', async () => {
        buildDashboardDom({ rowIds: [1], actionRequiredCount: 1 });
        setDashboardSearchActive(true);
        mockMembershipResponse({ incidentIds: [1, 2], totalCount: 2 });

        const pageRoot = document.getElementById('dashboard-page');
        const result = await reconcileReadyQueueMembership(pageRoot);

        expect(result).toBeNull();
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('suppresses heartbeat while quick filter search query is active', async () => {
        buildDashboardDom({ rowIds: [1], actionRequiredCount: 1 });
        setServiceCaseSearchQuery('SC00001');
        mockMembershipResponse({ incidentIds: [1, 2], totalCount: 2 });

        const pageRoot = document.getElementById('dashboard-page');
        const result = await reconcileReadyQueueMembership(pageRoot);

        expect(result).toBeNull();
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('suppresses heartbeat when the browser tab is hidden', async () => {
        buildDashboardDom({ rowIds: [1], actionRequiredCount: 1 });
        mockMembershipResponse({ incidentIds: [1, 2], totalCount: 2 });

        const previousDescriptor = Object.getOwnPropertyDescriptor(document, 'hidden');
        Object.defineProperty(document, 'hidden', {
            configurable: true,
            get: () => true,
        });

        const pageRoot = document.getElementById('dashboard-page');
        const result = await reconcileReadyQueueMembership(pageRoot);

        expect(result).toBeNull();
        expect(global.fetch).not.toHaveBeenCalled();

        if (previousDescriptor) {
            Object.defineProperty(document, 'hidden', previousDescriptor);
        } else {
            delete document.hidden;
        }
    });

    it('prevents overlapping in-flight heartbeat requests', async () => {
        buildDashboardDom({ rowIds: [1], actionRequiredCount: 1 });

        let resolveFetch;
        const membershipPayload = {
            kpi_strip_html: '<div data-kpi-strip>kpi</div>',
            service_case_filter_counts: { action_required: 2 },
            service_case_filter_count_variants: {
                operations_scope: { action_required: 2 },
            },
            incident_ids: [1, 2],
            total_count: 2,
            loaded_count: 2,
            has_more: false,
            membership: true,
            rows: [],
        };

        global.fetch = vi.fn().mockImplementation(() => new Promise((resolve) => {
            resolveFetch = () => resolve({
                ok: true,
                json: async () => membershipPayload,
            });
        }));

        const pageRoot = document.getElementById('dashboard-page');
        const first = reconcileReadyQueueMembership(pageRoot);
        const second = await reconcileReadyQueueMembership(pageRoot);

        expect(second).toBeNull();
        expect(global.fetch).toHaveBeenCalledTimes(1);

        resolveFetch();
        await first;
    });
});

describe('ready queue membership reconcile — DOM helpers', () => {
    it('reorderAutomaticWindow preserves load-more tail rows', () => {
        document.body.innerHTML = `
            <div class="dashboard-service-cases-card">
                <table><tbody id="dashboard-service-cases-body">
                    <tr id="service-case-row-1"><td>1</td></tr>
                    <tr id="service-case-row-2"><td>2</td></tr>
                    <tr id="service-case-row-99"><td>99</td></tr>
                </tbody></table>
            </div>
        `;

        const card = document.querySelector('.dashboard-service-cases-card');
        reorderAutomaticWindow(card, [2, 1]);

        const order = Array.from(card.querySelectorAll('tr[id^="service-case-row-"]'))
            .map((row) => Number(row.id.replace('service-case-row-', '')));

        expect(order).toEqual([2, 1, 99]);
        expect(arraysEqual([2, 1], getDomAutomaticWindowIds(card, 2))).toBe(true);
    });
});
