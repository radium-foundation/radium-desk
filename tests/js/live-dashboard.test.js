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
            <div id="dashboard-action-stats">stats-old</div>
            <div id="dashboard-sla-cards">sla-old</div>
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
                action_stats_html: 'stats-new',
                sla_cards_html: 'sla-new',
                rows: [],
                service_cases_empty: true,
                service_cases_empty_html: '',
            }),
        });

        const session = getWorkspaceSession();
        session.acquire('workspace-modal');

        await refreshDashboard(document.getElementById('dashboard-page'));

        expect(document.getElementById('dashboard-action-stats')?.textContent).toBe('stats-old');
        expect(document.getElementById('dashboard-sla-cards')?.textContent).toBe('sla-old');
        expect(document.querySelector('#service-case-row-10')).not.toBeNull();
    });

    it('coalesces pending payloads and applies only the latest on idle', async () => {
        const session = getWorkspaceSession();
        const onIdle = vi.fn();

        session.onIdle(onIdle);
        session.acquire('quick-create');

        queueDashboardRefresh({
            action_stats_html: 'stats-first',
            sla_cards_html: 'sla-first',
            rows: [],
            service_cases_empty: true,
            service_cases_empty_html: '',
        });

        queueDashboardRefresh({
            action_stats_html: 'stats-latest',
            sla_cards_html: 'sla-latest',
            rows: [],
            service_cases_empty: true,
            service_cases_empty_html: '',
        });

        session.release('quick-create');
        expect(onIdle).toHaveBeenCalledTimes(1);

        await flushPendingDashboardRefresh();

        expect(document.getElementById('dashboard-action-stats')?.textContent).toBe('stats-latest');
        expect(document.getElementById('dashboard-sla-cards')?.textContent).toBe('sla-latest');
    });

    it('applies refresh immediately when no session is active', async () => {
        await applyDashboardRefresh({
            action_stats_html: 'stats-new',
            sla_cards_html: 'sla-new',
            rows: [],
            service_cases_empty: true,
            service_cases_empty_html: '',
        });

        expect(document.getElementById('dashboard-action-stats')?.textContent).toBe('stats-new');
        expect(document.getElementById('dashboard-sla-cards')?.textContent).toBe('sla-new');
    });
});
