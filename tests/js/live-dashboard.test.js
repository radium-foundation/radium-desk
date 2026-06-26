import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applyDashboardRefresh,
    flushPendingDashboardRefresh,
    queueDashboardRefresh,
    refreshDashboard,
} from '../../resources/js/live-dashboard';
import { getWorkspaceSession, resetWorkspaceSession } from '../../resources/js/workspace/session';

describe('live dashboard refresh session integration', () => {
    beforeEach(() => {
        resetWorkspaceSession();
        document.body.innerHTML = `
            <div id="dashboard-page" data-live-url="/dashboard/live" data-live-filter="pending_admin"></div>
            <div id="dashboard-kpi-strip">stats-old</div>
            <div class="dashboard-service-cases-card">
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
            rows: [],
            service_cases_empty: true,
            service_cases_empty_html: '',
        });

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-new');
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
});
