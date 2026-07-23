import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applyDashboardRefresh,
    applyFilterCounts,
    flushPendingDashboardRefresh,
    queueDashboardRefresh,
    refreshDashboard,
    startPolling,
    stopPolling,
} from '../../resources/js/live-dashboard';
import { getWorkspaceSession, resetWorkspaceSession } from '../../resources/js/workspace/session';

describe('live dashboard refresh session integration', () => {
    beforeEach(() => {
        resetWorkspaceSession();
        stopPolling();
        document.body.innerHTML = `
            <div id="dashboard-page" data-live-url="/dashboard/live" data-live-filter="pending_admin"></div>
            <div id="dashboard-kpi-strip">stats-old</div>
            <div class="dashboard-service-cases-card">
                <span data-dashboard-case-filter-count="all">(0)</span>
                <span data-dashboard-case-filter-count="pending_admin">(0)</span>
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

        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        stopPolling();
        resetWorkspaceSession();
        vi.unstubAllGlobals();
    });

    it('queues refresh payloads while a workspace session is active', async () => {
        fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                kpi_strip_html: 'stats-new',
                rows: [],
                service_cases_empty: true,
                service_cases_empty_html: '',
            }),
        });

        const session = getWorkspaceSession();
        session.acquire('workspace-modal');

        await refreshDashboard(document.getElementById('dashboard-page'));

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-old');
        expect(document.querySelector('#service-case-row-10')).not.toBeNull();
    });

    it('coalesces pending payloads and applies only the latest on idle', async () => {
        const session = getWorkspaceSession();
        const onIdle = vi.fn();

        session.onIdle(onIdle);
        session.acquire('quick-create');

        queueDashboardRefresh({
            kpi_strip_html: 'stats-first',
            rows: [],
            service_cases_empty: true,
            service_cases_empty_html: '',
        });

        queueDashboardRefresh({
            kpi_strip_html: 'stats-latest',
            rows: [],
            service_cases_empty: true,
            service_cases_empty_html: '',
        });

        session.release('quick-create');
        expect(onIdle).toHaveBeenCalledTimes(1);

        await flushPendingDashboardRefresh();

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-latest');
    });

    it('applies refresh immediately when no session is active', async () => {
        await applyDashboardRefresh({
            kpi_strip_html: 'stats-new',
            service_case_filter_counts: {
                all: 12,
                pending_admin: 8,
            },
            rows: [],
            service_cases_empty: true,
            service_cases_empty_html: '',
        });

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-new');
        expect(document.querySelector('[data-dashboard-case-filter-count="all"]')?.textContent).toBe('(12)');
        expect(document.querySelector('[data-dashboard-case-filter-count="pending_admin"]')?.textContent).toBe('(8)');
    });

    it('updates filter chip counts from the refresh payload', () => {
        applyFilterCounts({
            all: 38,
            pending_admin: 12,
            completed: 0,
            high_priority: 0,
        });

        expect(document.querySelector('[data-dashboard-case-filter-count="all"]')?.textContent).toBe('(38)');
        expect(document.querySelector('[data-dashboard-case-filter-count="pending_admin"]')?.textContent).toBe('(12)');
    });

    it('defers applyDashboardRefresh when session becomes active before requestAnimationFrame', async () => {
        const session = getWorkspaceSession();

        const applyPromise = applyDashboardRefresh({
            kpi_strip_html: 'stats-new',
            rows: [],
            service_cases_empty: true,
            service_cases_empty_html: '',
        });

        session.acquire('bulk-selection', { incidentIds: [10] });
        await applyPromise;

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-old');

        session.release('bulk-selection');
        await flushPendingDashboardRefresh();

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-new');
    });

    it('queues bulk-selection refresh without DOM mutation and flushes the latest payload once on idle', async () => {
        fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                kpi_strip_html: 'stats-polled',
                rows: [{
                    incident_id: 99,
                    html: '<tr id="service-case-row-99"><td>SC00099</td></tr>',
                }],
                service_cases_empty: false,
                service_cases_empty_html: '',
            }),
        });

        const session = getWorkspaceSession();
        const onIdle = vi.fn();

        session.onIdle(onIdle);
        session.acquire('bulk-selection', { incidentIds: [10] });

        await refreshDashboard(document.getElementById('dashboard-page'));

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-old');
        expect(document.querySelector('#service-case-row-10')).not.toBeNull();
        expect(document.querySelector('#service-case-row-99')).toBeNull();

        queueDashboardRefresh({
            kpi_strip_html: 'stats-first',
            rows: [],
            service_cases_empty: true,
            service_cases_empty_html: '',
        });

        queueDashboardRefresh({
            kpi_strip_html: 'stats-latest',
            rows: [],
            service_cases_empty: true,
            service_cases_empty_html: '',
        });

        session.release('bulk-selection');
        expect(onIdle).toHaveBeenCalledTimes(1);

        await flushPendingDashboardRefresh();
        await flushPendingDashboardRefresh();

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-latest');
    });

    it('does not start a second live poll while the previous request is still pending', async () => {
        vi.useFakeTimers();
        vi.stubGlobal('requestAnimationFrame', (callback) => {
            callback(0);

            return 1;
        });

        try {
            let resolveFetch;
            fetch.mockImplementation(() => new Promise((resolve) => {
                resolveFetch = resolve;
            }));

            const pageRoot = document.getElementById('dashboard-page');
            startPolling(pageRoot, 1000);

            await vi.advanceTimersByTimeAsync(1000);
            expect(fetch).toHaveBeenCalledTimes(1);

            await vi.advanceTimersByTimeAsync(5000);
            expect(fetch).toHaveBeenCalledTimes(1);

            resolveFetch({
                ok: true,
                json: async () => ({
                    kpi_strip_html: 'stats-new',
                    rows: [],
                    service_cases_empty: true,
                    service_cases_empty_html: '',
                }),
            });

            await vi.runOnlyPendingTimersAsync();
            await Promise.resolve();
            await vi.advanceTimersByTimeAsync(1000);

            expect(fetch).toHaveBeenCalledTimes(2);
        } finally {
            stopPolling();
            vi.useRealTimers();
        }
    });

    it('logs refresh lifecycle suppression when refresh is already in flight', async () => {
        document.getElementById('dashboard-page').dataset.realtimeLifecycleDebug = '1';
        const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

        let resolveFetch;
        fetch.mockImplementation(() => new Promise((resolve) => {
            resolveFetch = resolve;
        }));

        const pageRoot = document.getElementById('dashboard-page');
        const firstRefresh = refreshDashboard(pageRoot, 'test-first');

        await Promise.resolve();

        const secondRefresh = refreshDashboard(pageRoot, 'test-second');

        await Promise.resolve();

        const payloads = warnSpy.mock.calls
            .filter(([label]) => label === '[dashboard-refresh-lifecycle]')
            .map(([, payload]) => payload);

        expect(payloads.some((payload) => payload.event === 'refreshDashboard_entered')).toBe(true);
        expect(payloads.some((payload) => (
            payload.event === 'refreshDashboard_suppressed'
            && payload.reason === 'refresh_in_flight'
        ))).toBe(true);

        await firstRefresh;
        await secondRefresh;

        warnSpy.mockRestore();
    });
});
