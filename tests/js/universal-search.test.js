import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initUniversalSearch } from '../../resources/js/universal-search';
import { resetDashboardSearchMode } from '../../resources/js/dashboard-search-mode';

describe('initUniversalSearch', () => {
    const originalLocation = window.location;

    beforeEach(() => {
        resetDashboardSearchMode();
        vi.stubGlobal('fetch', vi.fn());
        window.history.replaceState({}, '', '/');
    });

    afterEach(() => {
        document.body.innerHTML = '';
        resetDashboardSearchMode();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        window.history.replaceState({}, '', originalLocation.href);
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: originalLocation,
        });
    });

    it('bootstraps search from dashboard q query parameter on init', async () => {
        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                match_count: 1,
                incident_ids: [42],
                results: [{ incident_id: 42 }],
            }),
        }).mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                service_cases_empty: false,
                rows: [{
                    incident_id: 42,
                    html: '<tr id="service-case-row-42" data-incident-id="42"><td><a class="case-reference-link">SC03587</a></td><td>RD3437143</td></tr>',
                }],
            }),
        });

        window.history.replaceState({}, '', '/dashboard?q=RD3437143');

        document.body.innerHTML = `
            <form data-universal-search-form data-search-url="/search">
                <span data-universal-search-control>
                    <span data-universal-search-icon"><i class="bi bi-search"></i></span>
                </span>
                <input id="global-search-input" type="search" value="">
            </form>
            <div id="dashboard-page">
                <div class="dashboard-service-cases-card">
                    <div class="dashboard-search-banner d-none"
                         data-dashboard-search-banner
                         hidden>
                        <strong data-dashboard-search-banner-title>Search Results</strong>
                        <p data-dashboard-search-banner-message></p>
                        <button type="button" data-dashboard-search-clear>Clear Search</button>
                    </div>
                    <div id="dashboard-service-cases-scroll">
                        <table>
                            <thead><tr><th>Ref</th><th>Order</th></tr></thead>
                            <tbody id="dashboard-service-cases-body">
                                <tr id="service-case-row-10" data-incident-id="10">
                                    <td><a class="case-reference-link">SC00010</a></td>
                                    <td>RD-LOCAL-010</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;

        const applyRows = vi.fn();

        initUniversalSearch({
            dashboardIntegration: {
                pageRoot: document.getElementById('dashboard-page'),
                searchRowsUrl: '/dashboard/service-cases/search-rows',
                applyRows,
                restoreDashboard: vi.fn(),
            },
        });

        await vi.waitFor(() => {
            expect(fetch).toHaveBeenCalledWith(
                '/search?q=RD3437143',
                expect.any(Object),
            );
        });

        await vi.waitFor(() => {
            expect(applyRows).toHaveBeenCalled();
        });

        expect(document.getElementById('global-search-input')?.value).toBe('RD3437143');
    });

    it('redirects to dashboard search when dashboard integration is unavailable', () => {
        const assign = vi.fn();
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { assign },
        });

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
        expect(assign).toHaveBeenCalledWith('/dashboard?q=RD3434509');
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
