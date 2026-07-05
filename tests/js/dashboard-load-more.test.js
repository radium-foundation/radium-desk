import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initDashboardLoadMore } from '../../resources/js/dashboard-load-more';
import {
    initServiceCasePaginationState,
    setServiceCasePagination,
    setServiceCaseSearchQuery,
} from '../../resources/js/dashboard-service-case-state';

const buildLoadMorePage = ({ loaded = 35, total = 90 } = {}) => {
    document.body.innerHTML = `
        <div id="dashboard-page"
             data-live-filter="pending_admin"
             data-dashboard-load-more-url="/dashboard/service-cases/more">
            <div class="dashboard-service-cases-card"
                 data-service-cases-loaded="${loaded}"
                 data-service-case-filter-total="${total}"
                 data-service-case-filter="pending_admin">
                <table>
                    <tbody id="dashboard-service-cases-body"></tbody>
                </table>
                <div data-dashboard-load-more-wrap>
                    <button type="button" data-dashboard-load-more>Load More</button>
                </div>
            </div>
        </div>
    `;

    return document.getElementById('dashboard-page');
};

describe('dashboard load more visibility', () => {
    beforeEach(() => {
        initServiceCasePaginationState(document.getElementById('dashboard-page') ?? document);
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('hides the load more button when all records are loaded', () => {
        buildLoadMorePage({ loaded: 35, total: 35 });
        initServiceCasePaginationState();

        const wrap = document.querySelector('[data-dashboard-load-more-wrap]');

        expect(wrap?.classList.contains('d-none')).toBe(true);
    });

    it('shows the load more button while unloaded records remain', () => {
        buildLoadMorePage({ loaded: 35, total: 90 });
        initServiceCasePaginationState();

        const wrap = document.querySelector('[data-dashboard-load-more-wrap]');

        expect(wrap?.classList.contains('d-none')).toBe(false);
    });

    it('hides the load more button after pagination reaches the filtered total', () => {
        buildLoadMorePage({ loaded: 70, total: 90 });
        initServiceCasePaginationState();

        setServiceCasePagination({ loaded: 90, total: 90 });

        const wrap = document.querySelector('[data-dashboard-load-more-wrap]');

        expect(wrap?.classList.contains('d-none')).toBe(true);
    });
});

describe('initDashboardLoadMore', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
        buildLoadMorePage({ loaded: 35, total: 90 });
        initServiceCasePaginationState();
        setServiceCaseSearchQuery('fm 220');
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('includes the active quick filter query when loading more', async () => {
        setServiceCaseSearchQuery('fm 220');

        fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                rows: [],
                loaded_count: 60,
                total_count: 90,
            }),
        });

        initDashboardLoadMore({ pageRoot: document.getElementById('dashboard-page') });
        document.querySelector('[data-dashboard-load-more]')?.click();

        await vi.waitFor(() => {
            expect(fetch).toHaveBeenCalled();
        });

        expect(String(fetch.mock.calls[0]?.[0])).toContain('q=fm+220');
        expect(String(fetch.mock.calls[0]?.[0])).toContain('offset=35');
    });
});
