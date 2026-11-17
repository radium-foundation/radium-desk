import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { buildDashboardLiveQuery } from '../../resources/js/dashboard-live-query';

describe('buildDashboardLiveQuery', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <div id="dashboard-page"
                 data-live-workspace="my_attention"
                 data-live-queue="my_work"
                 data-live-filter="my_attention"></div>
        `;
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('sends workspace for agent action required without legacy queue params', () => {
        const pageRoot = document.getElementById('dashboard-page');
        const query = buildDashboardLiveQuery(pageRoot, { offset: 35 });

        expect(query.get('workspace')).toBe('my_attention');
        expect(query.has('queue')).toBe(false);
        expect(query.has('filter')).toBe(false);
        expect(query.get('offset')).toBe('35');
    });

    it('sends workspace for standard operation queue', () => {
        const pageRoot = document.getElementById('dashboard-page');
        pageRoot.dataset.liveWorkspace = 'scheduled';
        pageRoot.dataset.liveQueue = 'scheduled';
        pageRoot.dataset.liveFilter = 'scheduled';

        const query = buildDashboardLiveQuery(pageRoot);

        expect(query.get('workspace')).toBe('scheduled');
        expect(query.has('queue')).toBe(false);
    });

    it('uses kpi live workspace for kpis_only on hardware dashboard', () => {
        const pageRoot = document.getElementById('dashboard-page');
        pageRoot.dataset.liveWorkspace = 'hardware';
        pageRoot.dataset.liveQueue = 'hardware';
        pageRoot.dataset.kpiLiveWorkspace = 'action_required';

        const query = buildDashboardLiveQuery(pageRoot, { kpis_only: 1 });

        expect(query.get('workspace')).toBe('action_required');
        expect(query.get('kpis_only')).toBe('1');
    });
});
