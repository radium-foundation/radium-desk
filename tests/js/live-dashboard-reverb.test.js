import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    handleHybridIncidentsUpdated,
    handleKpisUpdated,
    handleReferenceNumbersUpdated,
    handleServiceCaseEvent,
    normalizeIncidentIds,
    resolveListAction,
} from '../../resources/js/live-dashboard-reverb';
import { getWorkspaceSession, resetWorkspaceSession } from '../../resources/js/workspace/session';

describe('live dashboard reverb handlers', () => {
    beforeEach(() => {
        resetWorkspaceSession();
        document.body.innerHTML = `
            <div id="dashboard-page"
                 data-live-queue="action_required"
                 data-live-filter="pending_admin"
                 data-live-rows-url="/dashboard/live/rows"></div>
            <div id="dashboard-kpi-strip">stats-old</div>
            <div class="dashboard-service-cases-card">
                <span data-dashboard-case-filter-count="action_required">(0)</span>
                <span data-dashboard-case-filter-count="waiting_customer">(0)</span>
                <div id="dashboard-service-cases-scroll">
                    <table>
                        <thead><tr><th>Ref</th></tr></thead>
                        <tbody id="dashboard-service-cases-body">
                            <tr id="service-case-row-10"><td>SC00010</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    });

    afterEach(() => {
        resetWorkspaceSession();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('updates KPI strip from DashboardKpisUpdated payload', async () => {
        await handleKpisUpdated({ kpi_strip_html: 'stats-live' });

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-live');
    });

    it('updates queue filter counts from DashboardKpisUpdated payload', async () => {
        await handleKpisUpdated({
            kpi_strip_html: 'stats-live',
            service_case_filter_count_variants: {
                operations_scope: {
                    action_required: 12,
                    waiting_customer: 4,
                },
            },
        });

        expect(document.querySelector('[data-dashboard-case-filter-count="action_required"]')?.textContent).toBe('(12)');
        expect(document.querySelector('[data-dashboard-case-filter-count="waiting_customer"]')?.textContent).toBe('(4)');
    });

    it('applies support scope counts when the active tab uses support_scope', async () => {
        document.getElementById('dashboard-page').dataset.liveScope = 'support_scope';

        await handleKpisUpdated({
            kpi_strip_html: 'stats-live',
            service_case_filter_count_variants: {
                support_scope: {
                    waiting_customer: 7,
                },
                operations_scope: {
                    waiting_customer: 99,
                },
            },
        });

        expect(document.querySelector('[data-dashboard-case-filter-count="waiting_customer"]')?.textContent).toBe('(7)');
    });

    it('merges a service case row when list action is add', async () => {
        const pageRoot = document.getElementById('dashboard-page');

        await handleServiceCaseEvent(pageRoot, {
            incident_id: 10,
            queue: 'action_required',
            list_actions: {
                action_required: 'add',
            },
            html: '<tr id="service-case-row-10"><td>SC00010 updated</td></tr>',
        });

        expect(document.querySelector('#service-case-row-10 td')?.textContent).toBe('SC00010 updated');
    });

    it('removes rows when list action is remove for the active queue', async () => {
        const pageRoot = document.getElementById('dashboard-page');

        await handleServiceCaseEvent(pageRoot, {
            incident_id: 10,
            queue: 'waiting_customer',
            list_actions: {
                action_required: 'remove',
            },
        });

        expect(document.querySelector('#service-case-row-10')).toBeNull();
    });

    it('ignores row updates when list action is ignore for the active queue', async () => {
        const pageRoot = document.getElementById('dashboard-page');

        await handleServiceCaseEvent(pageRoot, {
            incident_id: 10,
            queue: 'waiting_customer',
            list_actions: {
                waiting_customer: 'add',
            },
            html: '<tr id="service-case-row-10"><td>Should not apply</td></tr>',
        });

        expect(document.querySelector('#service-case-row-10 td')?.textContent).toBe('SC00010');
    });

    it('ignores row updates for locked incidents', async () => {
        const pageRoot = document.getElementById('dashboard-page');
        const session = getWorkspaceSession();

        session.acquire('inline-transaction', { incidentId: 10 });

        await handleServiceCaseEvent(pageRoot, {
            incident_id: 10,
            queue: 'action_required',
            list_actions: {
                action_required: 'update',
            },
            html: '<tr id="service-case-row-10"><td>Should not apply</td></tr>',
        });

        expect(document.querySelector('#service-case-row-10 td')?.textContent).toBe('SC00010');
    });

    it('queues updates while workspace session is active', async () => {
        const pageRoot = document.getElementById('dashboard-page');
        const session = getWorkspaceSession();

        session.acquire('workspace-modal');

        await handleKpisUpdated({ kpi_strip_html: 'stats-deferred' });

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-old');
    });

    it('resolves list action from the active queue tab', () => {
        const pageRoot = document.getElementById('dashboard-page');

        expect(resolveListAction(pageRoot, {
            list_actions: {
                action_required: 'remove',
                waiting_customer: 'add',
            },
        })).toBe('remove');

        pageRoot.dataset.liveQueue = 'waiting_customer';

        expect(resolveListAction(pageRoot, {
            list_actions: {
                action_required: 'remove',
                waiting_customer: 'add',
            },
        })).toBe('add');
    });

    it('normalizes bulk and single incident ids from ReferenceNumbersUpdated payloads', () => {
        expect(normalizeIncidentIds({ incident_ids: ['10', 11, 0, 'x'] })).toEqual([10, 11]);
        expect(normalizeIncidentIds({ incident_id: 12 })).toEqual([12]);
    });

    it('fetches row fragments for ReferenceNumbersUpdated and merges without KPI changes', async () => {
        const pageRoot = document.getElementById('dashboard-page');
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                rows: [{
                    incident_id: 10,
                    html: '<tr id="service-case-row-10"><td>SC00010 hybrid</td></tr>',
                }],
                remove_incident_ids: [],
            }),
        });

        vi.stubGlobal('fetch', fetchMock);

        await handleReferenceNumbersUpdated(pageRoot, {
            incident_ids: [10],
            updated_at: '2026-07-21T04:30:00Z',
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(String(fetchMock.mock.calls[0][0])).toContain('/dashboard/live/rows');
        expect(String(fetchMock.mock.calls[0][0])).toContain('ids');
        expect(document.querySelector('#service-case-row-10 td')?.textContent).toBe('SC00010 hybrid');
        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-old');
    });

    it('fetches row fragments for ServiceCasesAssigned without KPI changes', async () => {
        const pageRoot = document.getElementById('dashboard-page');
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                rows: [{
                    incident_id: 10,
                    html: '<tr id="service-case-row-10"><td>SC00010 assigned</td></tr>',
                }],
                remove_incident_ids: [],
            }),
        });

        vi.stubGlobal('fetch', fetchMock);

        await handleHybridIncidentsUpdated(pageRoot, {
            incident_ids: [10],
            incidents: [{
                incident_id: 10,
                queue: 'action_required',
                status: 'in_progress',
                updated_at: '2026-07-21T05:00:00Z',
            }],
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(document.querySelector('#service-case-row-10 td')?.textContent).toBe('SC00010 assigned');
        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-old');
    });

    it('skips ReferenceNumbersUpdated fetch for locked incidents', async () => {
        const pageRoot = document.getElementById('dashboard-page');
        const fetchMock = vi.fn();
        const session = getWorkspaceSession();

        session.acquire('inline-transaction', { incidentId: 10 });
        vi.stubGlobal('fetch', fetchMock);

        await handleReferenceNumbersUpdated(pageRoot, {
            incident_ids: [10],
        });

        expect(fetchMock).not.toHaveBeenCalled();
        expect(document.querySelector('#service-case-row-10 td')?.textContent).toBe('SC00010');
    });
});

describe('live dashboard reverb reconnect fallback', () => {
    it('exports initLiveDashboardReverb for wiring', async () => {
        const module = await import('../../resources/js/live-dashboard-reverb');

        expect(typeof module.initLiveDashboardReverb).toBe('function');
    });
});

describe('live dashboard reverb network recovery', () => {
    const connectionListeners = {};
    const mockConnection = {
        state: 'connected',
        bind: vi.fn((event, handler) => {
            connectionListeners[event] = handler;
        }),
        unbind: vi.fn(),
        disconnect: vi.fn(() => {
            mockConnection.state = 'disconnected';
            connectionListeners.disconnected?.();
        }),
        connect: vi.fn(() => {
            mockConnection.state = 'connected';
            connectionListeners.connected?.();
        }),
    };

    const refreshDashboard = vi.fn().mockResolvedValue(undefined);
    const startFastPolling = vi.fn();
    const startHeartbeatPolling = vi.fn();
    const stopPolling = vi.fn();

    beforeEach(() => {
        vi.resetModules();
        Object.keys(connectionListeners).forEach((key) => {
            delete connectionListeners[key];
        });
        mockConnection.state = 'connected';
        mockConnection.bind.mockClear();
        mockConnection.unbind.mockClear();
        mockConnection.disconnect.mockClear();
        mockConnection.connect.mockClear();
        refreshDashboard.mockClear();
        startFastPolling.mockClear();
        startHeartbeatPolling.mockClear();
        stopPolling.mockClear();

        document.body.innerHTML = `
            <meta name="csrf-token" content="test-token">
            <div id="dashboard-page"
                 data-echo-key="test-key"
                 data-echo-broadcaster="reverb"
                 data-user-id="42"
                 data-live-url="/dashboard/live"
                 data-live-updates-enabled="1"></div>
            <div id="notification-bell-root"></div>
        `;

        vi.doMock('laravel-echo', () => ({
            default: vi.fn().mockImplementation(() => ({
                private: vi.fn(() => ({
                    listen: vi.fn(),
                })),
                connector: {
                    pusher: {
                        connection: mockConnection,
                    },
                },
                disconnect: vi.fn(),
            })),
        }));

        vi.doMock('../../resources/js/live-dashboard', () => ({
            applyKpis: vi.fn(),
            applyPartialDashboardUpdate: vi.fn(),
            configureLiveDashboard: vi.fn(),
            refreshDashboard,
        }));

        vi.doMock('../../resources/js/live-dashboard-polling', () => ({
            destroyPolling: vi.fn(),
            startFastPolling,
            startHeartbeatPolling,
            stopPolling,
        }));
    });

    afterEach(() => {
        vi.doUnmock('laravel-echo');
        vi.doUnmock('../../resources/js/live-dashboard');
        vi.doUnmock('../../resources/js/live-dashboard-polling');
        vi.restoreAllMocks();
    });

    it('forces reconnect on browser online even when the socket still reports connected', async () => {
        const { initLiveDashboardReverb } = await import('../../resources/js/live-dashboard-reverb');
        const pageRoot = document.getElementById('dashboard-page');

        initLiveDashboardReverb({ pageRoot });
        mockConnection.disconnect.mockClear();
        mockConnection.connect.mockClear();
        refreshDashboard.mockClear();

        window.dispatchEvent(new Event('online'));

        expect(mockConnection.disconnect).toHaveBeenCalledTimes(1);
        expect(mockConnection.connect).toHaveBeenCalledTimes(1);
        expect(refreshDashboard).toHaveBeenCalledWith(pageRoot);
    });

    it('disconnects and starts fast polling when the browser goes offline', async () => {
        const { initLiveDashboardReverb } = await import('../../resources/js/live-dashboard-reverb');
        const pageRoot = document.getElementById('dashboard-page');

        initLiveDashboardReverb({ pageRoot });
        mockConnection.disconnect.mockClear();
        startFastPolling.mockClear();

        window.dispatchEvent(new Event('offline'));

        expect(mockConnection.disconnect).toHaveBeenCalledTimes(1);
        expect(stopPolling).toHaveBeenCalled();
        expect(startFastPolling).toHaveBeenCalledWith(pageRoot);
    });
});
