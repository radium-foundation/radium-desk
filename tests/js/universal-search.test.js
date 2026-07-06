import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initUniversalSearch } from '../../resources/js/universal-search';
import { resetDashboardSearchMode } from '../../resources/js/dashboard-search-mode';

describe('initUniversalSearch', () => {
    beforeEach(() => {
        resetDashboardSearchMode();
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        document.body.innerHTML = '';
        resetDashboardSearchMode();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    const mountSearch = () => {
        document.body.innerHTML = `
            <form data-universal-search-form data-search-url="/search">
                <span data-universal-search-control>
                    <span data-universal-search-icon><i class="bi bi-search"></i></span>
                </span>
                <input id="global-search-input" type="search" value="">
            </form>
        `;

        initUniversalSearch();
    };

    it('redirects to dashboard search when dashboard integration is unavailable', () => {
        delete window.location;
        window.location = { assign: vi.fn() };

        document.body.innerHTML = `
            <form data-universal-search-form data-search-url="/search" data-dashboard-url="/dashboard">
                <span data-universal-search-control>
                    <span data-universal-search-icon"><i class="bi bi-search"></i></span>
                </span>
                <input id="global-search-input" type="search" value="">
            </form>
        `;

        initUniversalSearch();

        document.getElementById('global-search-input').value = 'RD3434509';

        document.querySelector('[data-universal-search-form]')?.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }),
        );

        expect(fetch).not.toHaveBeenCalled();
        expect(window.location.assign).toHaveBeenCalledWith('/dashboard?q=RD3434509');
    });

    it('runs search on Enter when dashboard integration is provided', async () => {
        document.body.innerHTML = `
            <form data-universal-search-form data-search-url="/search">
                <input id="global-search-input" type="search" value="">
            </form>
            <div id="dashboard-page">
                <div class="dashboard-service-cases-card">
                    <div id="dashboard-service-cases-scroll">
                        <table><tbody id="dashboard-service-cases-body"></tbody></table>
                    </div>
                </div>
            </div>
        `;

        initUniversalSearch({
            dashboardIntegration: {
                pageRoot: document.getElementById('dashboard-page'),
                searchRowsUrl: '/dashboard/service-cases/search-rows',
                applyRows: vi.fn(),
                restoreDashboard: vi.fn(),
            },
        });

        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                match_count: 0,
                incident_ids: [],
                results: [],
            }),
        });

        document.getElementById('global-search-input').value = 'D';
        document.getElementById('global-search-input')?.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        );

        await Promise.resolve();
        await Promise.resolve();

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(fetch).toHaveBeenCalledWith(
            '/search?q=D',
            expect.any(Object),
        );
    });
});
