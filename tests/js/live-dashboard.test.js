import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applyDashboardRefresh,
    applyFilterCounts,
    applyPartialDashboardUpdate,
    flushPendingDashboardRefresh,
    queueDashboardRefresh,
    refreshDashboard,
    resetLiveDashboardRefreshStateForTests,
    startPolling,
    stopPolling,
} from '../../resources/js/live-dashboard';
import { getWorkspaceSession, resetWorkspaceSession } from '../../resources/js/workspace/session';

const multiRowDashboardHtml = `
    <div id="dashboard-page" data-live-url="/dashboard/live" data-live-filter="action_required" data-live-queue="action_required"></div>
    <div id="dashboard-kpi-strip">stats-old</div>
    <div class="dashboard-service-cases-card" data-service-cases-loaded="3">
        <span data-dashboard-case-filter-count="all">(3)</span>
        <span data-dashboard-case-filter-count="action_required">(3)</span>
        <span data-dashboard-case-filter-count="pending_admin">(0)</span>
        <div id="dashboard-service-cases-scroll">
            <table>
                <thead><tr><th>Ref</th></tr></thead>
                <tbody id="dashboard-service-cases-body">
                    <tr id="service-case-row-10"><td>SC00010</td></tr>
                    <tr id="service-case-row-11"><td>SC00011</td></tr>
                    <tr id="service-case-row-12"><td>SC00012</td></tr>
                </tbody>
            </table>
        </div>
    </div>
`;

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

    it('defers a second refresh while one is in flight and flushes it afterward', async () => {
        resetLiveDashboardRefreshStateForTests();
        vi.useRealTimers();
        stopPolling();

        document.getElementById('dashboard-page').dataset.realtimeLifecycleDebug = '1';
        const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

        let resolveFirstFetch;
        const fetchMock = vi.fn()
            .mockImplementationOnce(() => new Promise((resolve) => {
                resolveFirstFetch = resolve;
            }))
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    kpi_strip_html: 'stats-fresh',
                    service_case_filter_counts: {
                        all: 10,
                        pending_admin: 10,
                    },
                }),
            });

        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('requestAnimationFrame', (callback) => {
            callback(0);

            return 1;
        });

        const pageRoot = document.getElementById('dashboard-page');
        const firstRefresh = refreshDashboard(pageRoot, 'test-first');

        for (let attempt = 0; attempt < 20 && fetchMock.mock.calls.length === 0; attempt += 1) {
            await Promise.resolve();
        }

        expect(fetchMock).toHaveBeenCalledTimes(1);

        void refreshDashboard(pageRoot, 'hybrid-kpi-reconcile', { kpisOnly: true });

        await Promise.resolve();

        const payloads = warnSpy.mock.calls
            .filter(([label]) => label === '[dashboard-refresh-lifecycle]')
            .map(([, payload]) => payload);

        expect(payloads.some((payload) => payload.event === 'refreshDashboard_entered')).toBe(true);
        expect(payloads.some((payload) => (
            payload.event === 'refreshDashboard_deferred'
            && payload.reason === 'refresh_in_flight'
        ))).toBe(true);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        resolveFirstFetch({
            ok: true,
            json: async () => ({
                kpi_strip_html: 'stats-stale',
                service_case_filter_counts: {
                    all: 1,
                    pending_admin: 1,
                },
                rows: [],
                service_cases_empty: true,
                service_cases_empty_html: '',
            }),
        });

        await firstRefresh;

        for (let attempt = 0; attempt < 40 && fetchMock.mock.calls.length < 2; attempt += 1) {
            await Promise.resolve();
        }

        expect(fetchMock).toHaveBeenCalledTimes(2);

        for (let attempt = 0; attempt < 40; attempt += 1) {
            if (document.querySelector('[data-dashboard-case-filter-count="pending_admin"]')?.textContent === '(10)') {
                break;
            }

            await Promise.resolve();
        }

        expect(document.querySelector('[data-dashboard-case-filter-count="pending_admin"]')?.textContent).toBe('(10)');

        const deferredPayloads = warnSpy.mock.calls
            .filter(([label]) => label === '[dashboard-refresh-lifecycle]')
            .map(([, payload]) => payload);

        expect(deferredPayloads.some((payload) => (
            payload.event === 'refreshDashboard_deferred_flush'
            && payload.source === 'hybrid-kpi-reconcile'
        ))).toBe(true);

        warnSpy.mockRestore();
    });

    it('reveals a previously hidden zero-count queue tab when the count becomes positive', () => {
        document.body.innerHTML = `
            <div class="dashboard-service-cases-card" data-hide-zero-count-queue-tabs="true">
                <a href="#" role="tab" class="is-active">
                    <span data-dashboard-case-filter-count="attention">(1)</span>
                </a>
                <a href="#" role="tab" class="d-none">
                    <span data-dashboard-case-filter-count="action_required">(0)</span>
                </a>
            </div>
        `;

        applyFilterCounts({
            attention: 1,
            action_required: 10,
        });

        const readyTab = document.querySelector('[data-dashboard-case-filter-count="action_required"]')
            ?.closest('[role="tab"]');

        expect(readyTab?.classList.contains('d-none')).toBe(false);
        expect(document.querySelector('[data-dashboard-case-filter-count="action_required"]')?.textContent).toBe('(10)');
    });

    it('preserves queued filter counts when a later rows-only partial arrives during a workspace session', async () => {
        vi.stubGlobal('requestAnimationFrame', (callback) => {
            callback(0);

            return 1;
        });

        const session = getWorkspaceSession();
        session.acquire('workspace-modal');

        queueDashboardRefresh({
            kpi_strip_html: 'stats-kpi',
            service_case_filter_counts: {
                all: 15,
                pending_admin: 10,
            },
        });

        queueDashboardRefresh({
            rows: [{
                incident_id: 10,
                html: '<tr id="service-case-row-10"><td>SC00010 updated</td></tr>',
            }],
            service_cases_empty: false,
            service_cases_empty_html: '',
        });

        session.release('workspace-modal');
        await flushPendingDashboardRefresh();

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-kpi');
        expect(document.querySelector('[data-dashboard-case-filter-count="pending_admin"]')?.textContent).toBe('(10)');
        expect(document.querySelector('#service-case-row-10 td')?.textContent).toBe('SC00010 updated');
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

        expect(fetch).toHaveBeenCalledWith(
            expect.stringContaining('kpis_only=1'),
            expect.any(Object),
        );
        expect(fetch.mock.calls[0][0]).not.toContain('limit=');
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
            authoritative: true,
        });

        expect(document.querySelector('#service-case-row-10')).toBeNull();
        expect(document.querySelector('[data-dashboard-case-filter-count="pending_admin"]')?.textContent).toBe('(0)');
    });
});

describe('live dashboard partial patch semantics', () => {
    beforeEach(() => {
        resetWorkspaceSession();
        stopPolling();
        document.body.innerHTML = multiRowDashboardHtml;
        vi.stubGlobal('fetch', vi.fn());
        vi.stubGlobal('requestAnimationFrame', (callback) => {
            callback(0);

            return 1;
        });
    });

    afterEach(() => {
        stopPolling();
        resetLiveDashboardRefreshStateForTests();
        resetWorkspaceSession();
        vi.unstubAllGlobals();
    });

    it('partial patch updates one row without deleting siblings', async () => {
        await applyPartialDashboardUpdate({
            rows: [{
                incident_id: 11,
                html: '<tr id="service-case-row-11"><td>SC00011 patched</td></tr>',
            }],
            service_case_filter_counts: {
                action_required: 3,
            },
        });

        expect(document.querySelector('#service-case-row-10')).not.toBeNull();
        expect(document.querySelector('#service-case-row-11 td')?.textContent).toBe('SC00011 patched');
        expect(document.querySelector('#service-case-row-12')).not.toBeNull();
        expect(document.querySelector('[data-dashboard-case-filter-count="action_required"]')?.textContent).toBe('(3)');
    });

    it('remove_incident_ids deletes only the listed rows', async () => {
        await applyPartialDashboardUpdate({
            remove_incident_ids: [11],
            service_case_filter_counts: {
                action_required: 2,
            },
        });

        expect(document.querySelector('#service-case-row-10')).not.toBeNull();
        expect(document.querySelector('#service-case-row-11')).toBeNull();
        expect(document.querySelector('#service-case-row-12')).not.toBeNull();
    });

    it('buffers partial patches during workspace session and flushes without snapshot wipe', async () => {
        const session = getWorkspaceSession();
        session.acquire('workspace-modal');

        await applyPartialDashboardUpdate({
            rows: [{
                incident_id: 10,
                html: '<tr id="service-case-row-10"><td>SC00010 buffered</td></tr>',
            }],
            remove_incident_ids: [12],
            service_case_filter_counts: {
                action_required: 2,
            },
        });

        expect(document.querySelector('#service-case-row-10 td')?.textContent).toBe('SC00010');
        expect(document.querySelector('#service-case-row-12')).not.toBeNull();

        session.release('workspace-modal');
        await flushPendingDashboardRefresh();

        expect(document.querySelector('#service-case-row-10 td')?.textContent).toBe('SC00010 buffered');
        expect(document.querySelector('#service-case-row-11')).not.toBeNull();
        expect(document.querySelector('#service-case-row-12')).toBeNull();
    });

    it('preserves remove_incident_ids across multiple queued partials', async () => {
        const session = getWorkspaceSession();
        session.acquire('workspace-modal');

        await applyPartialDashboardUpdate({
            remove_incident_ids: [11],
        });
        await applyPartialDashboardUpdate({
            rows: [{
                incident_id: 10,
                html: '<tr id="service-case-row-10"><td>SC00010 multi</td></tr>',
            }],
            remove_incident_ids: [12],
        });

        session.release('workspace-modal');
        await flushPendingDashboardRefresh();

        expect(document.querySelector('#service-case-row-10 td')?.textContent).toBe('SC00010 multi');
        expect(document.querySelector('#service-case-row-11')).toBeNull();
        expect(document.querySelector('#service-case-row-12')).toBeNull();
    });

    it('does not let a 1-row partial overwrite a queued full snapshot', async () => {
        const session = getWorkspaceSession();
        session.acquire('workspace-modal');

        queueDashboardRefresh({
            authoritative: true,
            kpi_strip_html: 'stats-full',
            service_case_filter_counts: {
                action_required: 3,
            },
            rows: [
                { incident_id: 10, html: '<tr id="service-case-row-10"><td>SC00010 full</td></tr>' },
                { incident_id: 11, html: '<tr id="service-case-row-11"><td>SC00011 full</td></tr>' },
                { incident_id: 12, html: '<tr id="service-case-row-12"><td>SC00012 full</td></tr>' },
            ],
            service_cases_empty: false,
            loaded_count: 3,
            total_count: 3,
        });

        await applyPartialDashboardUpdate({
            rows: [{
                incident_id: 10,
                html: '<tr id="service-case-row-10"><td>SC00010 patch</td></tr>',
            }],
        });

        session.release('workspace-modal');
        await flushPendingDashboardRefresh();

        expect(document.querySelector('#service-case-row-10 td')?.textContent).toBe('SC00010 patch');
        expect(document.querySelector('#service-case-row-11 td')?.textContent).toBe('SC00011 full');
        expect(document.querySelector('#service-case-row-12 td')?.textContent).toBe('SC00012 full');
    });

    it('lets a later full snapshot replace earlier partial patches', async () => {
        const session = getWorkspaceSession();
        session.acquire('workspace-modal');

        await applyPartialDashboardUpdate({
            rows: [{
                incident_id: 10,
                html: '<tr id="service-case-row-10"><td>SC00010 stale patch</td></tr>',
            }],
        });

        queueDashboardRefresh({
            authoritative: true,
            kpi_strip_html: 'stats-snapshot',
            service_case_filter_counts: {
                action_required: 2,
            },
            rows: [
                { incident_id: 11, html: '<tr id="service-case-row-11"><td>SC00011 snap</td></tr>' },
                { incident_id: 12, html: '<tr id="service-case-row-12"><td>SC00012 snap</td></tr>' },
            ],
            service_cases_empty: false,
            loaded_count: 2,
            total_count: 2,
        });

        session.release('workspace-modal');
        await flushPendingDashboardRefresh();

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-snapshot');
        expect(document.querySelector('#service-case-row-10')).toBeNull();
        expect(document.querySelector('#service-case-row-11 td')?.textContent).toBe('SC00011 snap');
        expect(document.querySelector('#service-case-row-12 td')?.textContent).toBe('SC00012 snap');
    });

    it('authoritative snapshot merge still removes absent rows', async () => {
        await applyDashboardRefresh({
            authoritative: true,
            kpi_strip_html: 'stats-snap',
            service_case_filter_counts: {
                action_required: 1,
            },
            rows: [
                { incident_id: 11, html: '<tr id="service-case-row-11"><td>SC00011 only</td></tr>' },
            ],
            service_cases_empty: false,
            loaded_count: 1,
            total_count: 1,
        });

        expect(document.querySelector('#service-case-row-10')).toBeNull();
        expect(document.querySelector('#service-case-row-11 td')?.textContent).toBe('SC00011 only');
        expect(document.querySelector('#service-case-row-12')).toBeNull();
    });
});
