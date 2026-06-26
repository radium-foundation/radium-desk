import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    handleKpisUpdated,
    handleServiceCaseEvent,
    shouldRemoveRowForFilter,
} from '../../resources/js/live-dashboard-reverb';
import { getWorkspaceSession, resetWorkspaceSession } from '../../resources/js/workspace/session';

describe('live dashboard reverb handlers', () => {
    beforeEach(() => {
        resetWorkspaceSession();
        document.body.innerHTML = `
            <div id="dashboard-page" data-live-filter="pending_admin"></div>
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
    });

    afterEach(() => {
        resetWorkspaceSession();
    });

    it('updates KPI strip from DashboardKpisUpdated payload', async () => {
        await handleKpisUpdated({ kpi_strip_html: 'stats-live' });

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-live');
    });

    it('merges a service case row from realtime payload', async () => {
        const pageRoot = document.getElementById('dashboard-page');

        await handleServiceCaseEvent(pageRoot, {
            incident_id: 10,
            html: '<tr id="service-case-row-10"><td>SC00010 updated</td></tr>',
            remove_from_list: false,
        });

        expect(document.querySelector('#service-case-row-10 td')?.textContent).toBe('SC00010 updated');
    });

    it('removes rows flagged for pending_admin filter', async () => {
        const pageRoot = document.getElementById('dashboard-page');

        await handleServiceCaseEvent(pageRoot, {
            incident_id: 10,
            html: '',
            remove_from_list: true,
        });

        expect(document.querySelector('#service-case-row-10')).toBeNull();
    });

    it('ignores row updates for locked incidents', async () => {
        const pageRoot = document.getElementById('dashboard-page');
        const session = getWorkspaceSession();

        session.acquire('inline-transaction', { incidentId: 10 });

        await handleServiceCaseEvent(pageRoot, {
            incident_id: 10,
            html: '<tr id="service-case-row-10"><td>Should not apply</td></tr>',
            remove_from_list: false,
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

    it('detects remove_from_list for pending filters only', () => {
        const pageRoot = document.getElementById('dashboard-page');

        expect(shouldRemoveRowForFilter(pageRoot, { remove_from_list: true })).toBe(true);

        pageRoot.dataset.liveFilter = 'completed';

        expect(shouldRemoveRowForFilter(pageRoot, { remove_from_list: true })).toBe(false);
    });
});

describe('live dashboard reverb reconnect fallback', () => {
    it('exports initLiveDashboardReverb for wiring', async () => {
        const module = await import('../../resources/js/live-dashboard-reverb');

        expect(typeof module.initLiveDashboardReverb).toBe('function');
    });
});
