import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applyDashboardRefresh,
    applyFilterCounts,
    flushPendingDashboardRefresh,
    queueDashboardRefresh,
    refreshDashboard,
    resetLiveDashboardRefreshStateForTests,
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
        resetLiveDashboardRefreshStateForTests();
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

    it('hides inactive zero-count queue tabs when configured', () => {
        document.body.innerHTML = `
            <div class="dashboard-service-cases-card" data-hide-zero-count-queue-tabs="true">
                <a href="#" role="tab" class="is-active">
                    <span data-dashboard-case-filter-count="attention">(1)</span>
                </a>
                <a href="#" role="tab">
                    <span data-dashboard-case-filter-count="pending_review">(0)</span>
                </a>
            </div>
        `;

        applyFilterCounts({
            attention: 1,
            pending_review: 0,
        });

        const tabs = document.querySelectorAll('[role="tab"]');

        expect(tabs[0]?.classList.contains('d-none')).toBe(false);
        expect(tabs[1]?.classList.contains('d-none')).toBe(true);
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
            resetLiveDashboardRefreshStateForTests();
            vi.useRealTimers();
            await Promise.resolve();
            await Promise.resolve();
        }
    });

    it('logs refresh lifecycle suppression when refresh is already in flight', async () => {
        resetLiveDashboardRefreshStateForTests();
        vi.useRealTimers();
        stopPolling();

        document.getElementById('dashboard-page').dataset.realtimeLifecycleDebug = '1';
        const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

        let resolveFetch;
        const fetchMock = vi.fn().mockImplementation(() => new Promise((resolve) => {
            resolveFetch = resolve;
        }));

        vi.stubGlobal('fetch', fetchMock);

        const pageRoot = document.getElementById('dashboard-page');
        const firstRefresh = refreshDashboard(pageRoot, 'test-first');

        for (let attempt = 0; attempt < 20 && fetchMock.mock.calls.length === 0; attempt += 1) {
            await Promise.resolve();
        }

        expect(fetchMock).toHaveBeenCalled();

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

        resolveFetch({
            ok: true,
            json: async () => ({
                kpi_strip_html: 'stats-new',
                rows: [],
                service_cases_empty: true,
                service_cases_empty_html: '',
            }),
        });

        warnSpy.mockRestore();
    });

    it('applies KPI strip and filter counts only when kpisOnly is set', async () => {
        vi.stubGlobal('requestAnimationFrame', (callback) => {
            callback(0);

            return 1;
        });

        fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                kpi_strip_html: 'stats-reconciled',
                service_case_filter_counts: {
                    all: 5,
                    pending_admin: 2,
                },
                rows: [{
                    incident_id: 99,
                    html: '<tr id="service-case-row-99"><td>SC00099</td></tr>',
                }],
            }),
        });

        await refreshDashboard(document.getElementById('dashboard-page'), 'test-kpis-only', {
            kpisOnly: true,
        });

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-reconciled');
        expect(document.querySelector('[data-dashboard-case-filter-count="all"]')?.textContent).toBe('(5)');
        expect(document.querySelector('#service-case-row-10')).not.toBeNull();
        expect(document.querySelector('#service-case-row-99')).toBeNull();
    });

    it('does not wipe grid rows when flushing a KPI-only pending refresh', async () => {
        vi.stubGlobal('requestAnimationFrame', (callback) => {
            callback(0);

            return 1;
        });

        const session = getWorkspaceSession();
        session.acquire('workspace-modal');

        queueDashboardRefresh({
            kpi_strip_html: 'stats-kpi-only',
            service_case_filter_counts: {
                all: 15,
                pending_admin: 10,
            },
        });

        session.release('workspace-modal');
        await flushPendingDashboardRefresh();

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-kpi-only');
        expect(document.querySelector('[data-dashboard-case-filter-count="pending_admin"]')?.textContent).toBe('(10)');
        expect(document.querySelector('#service-case-row-10')).not.toBeNull();
    });

    it('queues kpisOnly refresh without wiping visible rows after workspace idle flush', async () => {
        vi.stubGlobal('requestAnimationFrame', (callback) => {
            callback(0);

            return 1;
        });

        fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                kpi_strip_html: 'stats-hybrid-reconcile',
                service_case_filter_counts: {
                    all: 15,
                    pending_admin: 10,
                },
                rows: [{
                    incident_id: 99,
                    html: '<tr id="service-case-row-99"><td>SC00099</td></tr>',
                }],
            }),
        });

        const session = getWorkspaceSession();
        session.acquire('workspace-modal');

        await refreshDashboard(document.getElementById('dashboard-page'), 'hybrid-kpi-reconcile', {
            kpisOnly: true,
        });

        expect(document.querySelector('#service-case-row-10')).not.toBeNull();

        session.release('workspace-modal');
        await flushPendingDashboardRefresh();

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-hybrid-reconcile');
        expect(document.querySelector('[data-dashboard-case-filter-count="pending_admin"]')?.textContent).toBe('(10)');
        expect(document.querySelector('#service-case-row-10')).not.toBeNull();
        expect(document.querySelector('#service-case-row-99')).toBeNull();
    });

    it('preserves queued rows when a later KPI-only payload arrives during a workspace session', async () => {
        vi.stubGlobal('requestAnimationFrame', (callback) => {
            callback(0);

            return 1;
        });

        const session = getWorkspaceSession();
        session.acquire('workspace-modal');

        queueDashboardRefresh({
            kpi_strip_html: 'stats-full',
            service_case_filter_counts: {
                all: 2,
                pending_admin: 2,
            },
            rows: [
                {
                    incident_id: 10,
                    html: '<tr id="service-case-row-10"><td>SC00010</td></tr>',
                },
                {
                    incident_id: 11,
                    html: '<tr id="service-case-row-11"><td>SC00011</td></tr>',
                },
            ],
            service_cases_empty: false,
            service_cases_empty_html: '',
        });

        queueDashboardRefresh({
            kpi_strip_html: 'stats-kpi-latest',
            service_case_filter_counts: {
                all: 2,
                pending_admin: 2,
            },
        });

        session.release('workspace-modal');
        await flushPendingDashboardRefresh();

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-kpi-latest');
        expect(document.querySelector('#service-case-row-10')).not.toBeNull();
        expect(document.querySelector('#service-case-row-11')).not.toBeNull();
    });

    it('still clears the grid when an explicit empty rows payload is flushed', async () => {
        vi.stubGlobal('requestAnimationFrame', (callback) => {
            callback(0);

            return 1;
        });

        await applyDashboardRefresh({
            kpi_strip_html: 'stats-empty',
            service_case_filter_counts: {
                all: 0,
                pending_admin: 0,
            },
            rows: [],
            service_cases_empty: true,
            service_cases_empty_html: '',
        });

        expect(document.querySelector('#service-case-row-10')).toBeNull();
        expect(document.querySelector('[data-dashboard-case-filter-count="pending_admin"]')?.textContent).toBe('(0)');
    });
});
