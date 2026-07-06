import { afterEach, describe, expect, it, vi } from 'vitest';
import { getDashboardConfig } from '../../resources/js/dashboard-config';
import { initDashboardQuickFilter } from '../../resources/js/dashboard-filter';
import { initDashboardLoadMore } from '../../resources/js/dashboard-load-more';
import { initOperationsDashboard } from '../../resources/js/operations-dashboard';

describe('non-dashboard page bootstrap safety', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('returns null dashboard config when dashboard-page is absent', () => {
        document.body.innerHTML = `
            <div id="operations-dashboard-root" data-live-url="/admin/operations/live"></div>
        `;

        expect(getDashboardConfig()).toBeNull();
    });

    it('does not throw when dashboard quick filter runs without dashboard config', () => {
        document.body.innerHTML = `
            <div id="operations-dashboard-root" data-live-url="/admin/operations/live"></div>
        `;

        expect(() => initDashboardQuickFilter({ pageRoot: document })).not.toThrow();
        expect(initDashboardQuickFilter({ pageRoot: document })).toBeNull();
    });

    it('does not throw when dashboard load more runs without dashboard config', () => {
        document.body.innerHTML = `
            <div id="operations-dashboard-root" data-live-url="/admin/operations/live"></div>
        `;

        expect(() => initDashboardLoadMore({ pageRoot: document })).not.toThrow();
        expect(initDashboardLoadMore({ pageRoot: document })).toBeNull();
    });

    it('still initializes operations dashboard without dashboard config', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                generated_at: '2026-07-06T08:00:00.000Z',
                html: {},
            }),
        }));

        document.body.innerHTML = `
            <div id="operations-dashboard-root" data-live-url="/admin/operations/live">
                <div id="operations-tab-today-content">
                    <div class="operations-lazy-placeholder card border-0 shadow-sm">
                        <div class="card-body py-4 text-center text-muted">
                            <span>Loading support intelligence…</span>
                        </div>
                    </div>
                </div>
            </div>
        `;

        await expect(initOperationsDashboard()).resolves.toBeUndefined();
        expect(fetch).toHaveBeenCalled();
    });
});

describe('dashboard page config', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('reads dashboard URLs from dashboard-page dataset', () => {
        document.body.innerHTML = `
            <div
                id="dashboard-page"
                data-dashboard-load-more-url="/dashboard/service-cases/load-more"
                data-dashboard-search-rows-url="/dashboard/service-cases/search-rows"
            ></div>
        `;

        expect(getDashboardConfig()).toEqual({
            pageRoot: document.getElementById('dashboard-page'),
            dashboardLoadMoreUrl: '/dashboard/service-cases/load-more',
            dashboardSearchRowsUrl: '/dashboard/service-cases/search-rows',
        });
    });

    it('initializes dashboard load more when config URLs are present', () => {
        document.body.innerHTML = `
            <div
                id="dashboard-page"
                data-dashboard-load-more-url="/dashboard/service-cases/load-more"
                data-live-filter="action_required"
            >
                <div class="dashboard-service-cases-card" data-service-case-filter="action_required">
                    <button type="button" data-dashboard-load-more>Load more</button>
                </div>
            </div>
        `;

        const pageRoot = document.getElementById('dashboard-page');

        expect(initDashboardLoadMore({ pageRoot })).not.toBeNull();
    });
});
