import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initUniversalSearch } from '../../resources/js/universal-search';
import { initCustomer360Drawer } from '../../resources/js/customer-360-drawer';
import { applyRows, refreshDashboard } from '../../resources/js/live-dashboard';
import { isDashboardSearchActive, resetDashboardSearchMode } from '../../resources/js/dashboard-search-mode';
import { resetWorkspaceSession } from '../../resources/js/workspace/session';

describe('dashboard global search integration', () => {
    beforeEach(() => {
        resetWorkspaceSession();
        resetDashboardSearchMode();
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        document.body.innerHTML = '';
        resetDashboardSearchMode();
        resetWorkspaceSession();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    const mountDashboard = () => {
        document.body.innerHTML = `
            <form data-universal-search-form data-search-url="/search">
                <span data-universal-search-control>
                    <span data-universal-search-icon><i class="bi bi-search"></i></span>
                </span>
                <input id="global-search-input" type="search" value="">
            </form>
            <div id="dashboard-page"
                 data-live-url="/dashboard/live"
                 data-live-filter="all"
                 data-customer-360-url="http://localhost/dashboard/service-cases"
                 data-dashboard-search-rows-url="/dashboard/service-cases/search-rows">
                <div class="dashboard-service-cases-card">
                    <div id="dashboard-service-cases-content">
                        <div class="dashboard-search-banner d-none"
                             data-dashboard-search-banner
                             hidden
                             role="status">
                            <div class="dashboard-search-banner__content">
                                <strong data-dashboard-search-banner-title>Search Results</strong>
                                <p data-dashboard-search-banner-message></p>
                                <button type="button" data-dashboard-search-clear>Clear Search</button>
                            </div>
                        </div>
                        <div id="dashboard-service-cases-scroll">
                        <table>
                            <thead><tr><th>Ref</th><th>Order</th></tr></thead>
                            <tbody id="dashboard-service-cases-body">
                                <tr id="service-case-row-10" data-incident-id="10">
                                    <td><a href="/incidents/10" class="case-reference-link">SC00010</a></td>
                                    <td>RD-LOCAL-010</td>
                                </tr>
                                <tr id="service-case-row-20" data-incident-id="20">
                                    <td><a href="/incidents/20" class="case-reference-link">SC00020</a></td>
                                    <td>RD-LOCAL-020</td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
            </div>
            <div data-customer-360-drawer aria-hidden="true">
                <div data-customer-360-backdrop></div>
                <aside data-customer-360-panel">
                    <button type="button" data-customer-360-close"></button>
                    <span data-customer-360-subtitle"></span>
                    <div data-customer-360-loading hidden></div>
                    <div data-customer-360-error class="d-none"></div>
                    <div data-customer-360-content-host"></div>
                </aside>
            </div>
        `;

        const pageRoot = document.getElementById('dashboard-page');
        const customer360Drawer = initCustomer360Drawer({
            pageRoot,
            showToast: vi.fn(),
        });

        initUniversalSearch({
            dashboardIntegration: {
                pageRoot,
                searchRowsUrl: pageRoot.dataset.dashboardSearchRowsUrl,
                applyRows: (rows, options = {}) => {
                    applyRows(rows, options);
                },
                restoreDashboard: () => refreshDashboard(pageRoot),
                openDrawer: (incidentId, referenceLabel) => customer360Drawer?.open(incidentId, referenceLabel),
                closeDrawer: () => customer360Drawer?.close(),
                onRowsUpdated: vi.fn(),
            },
        });

        return { pageRoot, customer360Drawer };
    };

    const submitSearch = async (query) => {
        document.getElementById('global-search-input').value = query;
        document.querySelector('[data-universal-search-form]')?.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }),
        );

        await vi.waitFor(() => {
            expect(isDashboardSearchActive()).toBe(true);
            expect(document.querySelector('[data-dashboard-search-banner]')?.hidden).toBe(false);
        });

        await vi.waitFor(() => {
            expect(document.querySelector('[data-universal-search-icon]')?.querySelector('.spinner-border')).toBeNull();
        });
    };

    it('auto-opens drawer when exactly one search result is returned', async () => {
        mountDashboard();

        fetch
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    match_count: 1,
                    incident_ids: [42],
                    results: [{ incident_id: 42 }],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    service_cases_empty: false,
                    rows: [{
                        incident_id: 42,
                        html: `
                            <tr id="service-case-row-42" data-incident-id="42">
                                <td><a href="/incidents/42" class="case-reference-link">SC00042</a></td>
                                <td>RD-MATCH-042</td>
                            </tr>
                        `,
                    }],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                text: async () => '<div data-customer-360-content>Drawer loaded</div>',
            });

        await submitSearch('RD-MATCH-042');

        await vi.waitFor(() => {
            expect(document.querySelector('[data-customer-360-drawer]')?.classList.contains('is-open')).toBe(true);
        });

        expect(fetch).toHaveBeenCalledWith(
            '/search?q=RD-MATCH-042',
            expect.objectContaining({
                headers: expect.objectContaining({ Accept: 'application/json' }),
            }),
        );
        expect(fetch).toHaveBeenCalledWith(
            '/dashboard/service-cases/search-rows?ids%5B%5D=42',
            expect.any(Object),
        );

        expect(document.getElementById('service-case-row-42')).not.toBeNull();
        expect(document.getElementById('service-case-row-10')).toBeNull();
        expect(document.getElementById('service-case-row-20')).toBeNull();
        expect(document.querySelector('[data-customer-360-drawer]')?.classList.contains('is-open')).toBe(true);
        expect(document.getElementById('service-case-row-42')?.classList.contains('dashboard-case-row--search-match')).toBe(true);
        expect(isDashboardSearchActive()).toBe(true);
    });

    it('shows only matching rows for multiple search results without opening drawer', async () => {
        mountDashboard();

        fetch
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    match_count: 2,
                    incident_ids: [42, 43],
                    results: [{ incident_id: 42 }, { incident_id: 43 }],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    service_cases_empty: false,
                    rows: [
                        {
                            incident_id: 42,
                            html: '<tr id="service-case-row-42" data-incident-id="42"><td><a class="case-reference-link">SC00042</a></td><td>RD-A</td></tr>',
                        },
                        {
                            incident_id: 43,
                            html: '<tr id="service-case-row-43" data-incident-id="43"><td><a class="case-reference-link">SC00043</a></td><td>RD-B</td></tr>',
                        },
                    ],
                }),
            });

        await submitSearch('RD-MULTI');

        expect(document.getElementById('service-case-row-42')).not.toBeNull();
        expect(document.getElementById('service-case-row-43')).not.toBeNull();
        expect(document.getElementById('service-case-row-10')).toBeNull();
        expect(document.querySelector('[data-customer-360-drawer]')?.classList.contains('is-open')).toBe(false);
    });

    it('restores dashboard rows when search is cleared', async () => {
        mountDashboard();

        fetch
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    match_count: 1,
                    incident_ids: [42],
                    results: [{ incident_id: 42 }],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    service_cases_empty: false,
                    rows: [{
                        incident_id: 42,
                        html: '<tr id="service-case-row-42" data-incident-id="42"><td><a class="case-reference-link">SC00042</a></td><td>RD-MATCH-042</td></tr>',
                    }],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                text: async () => '<div>Drawer</div>',
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    service_cases_empty: false,
                    rows: [
                        {
                            incident_id: 10,
                            html: '<tr id="service-case-row-10" data-incident-id="10"><td><a class="case-reference-link">SC00010</a></td><td>RD-LOCAL-010</td></tr>',
                        },
                        {
                            incident_id: 20,
                            html: '<tr id="service-case-row-20" data-incident-id="20"><td><a class="case-reference-link">SC00020</a></td><td>RD-LOCAL-020</td></tr>',
                        },
                    ],
                }),
            });

        await submitSearch('RD-MATCH-042');

        expect(document.getElementById('service-case-row-42')).not.toBeNull();
        expect(isDashboardSearchActive()).toBe(true);

        const input = document.getElementById('global-search-input');
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        await vi.waitFor(() => {
            expect(document.getElementById('service-case-row-10')).not.toBeNull();
        });

        expect(fetch).toHaveBeenCalledWith(
            '/dashboard/live?filter=all',
            expect.any(Object),
        );
        expect(document.getElementById('service-case-row-10')).not.toBeNull();
        expect(document.getElementById('service-case-row-20')).not.toBeNull();
        expect(document.getElementById('service-case-row-42')).toBeNull();
        expect(isDashboardSearchActive()).toBe(false);
        expect(document.querySelector('[data-customer-360-drawer]')?.classList.contains('is-open')).toBe(false);
    });

    it('does not search while typing', async () => {
        mountDashboard();

        const input = document.getElementById('global-search-input');
        input.value = 'partial';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        await Promise.resolve();

        expect(fetch).not.toHaveBeenCalled();
    });

    it('does not send view or filter parameters with search request', async () => {
        mountDashboard();

        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                match_count: 0,
                incident_ids: [],
                results: [],
            }),
        });

        await submitSearch('9876543210');

        const searchRequestUrl = fetch.mock.calls[0][0];
        expect(searchRequestUrl).toBe('/search?q=9876543210');
        expect(searchRequestUrl).not.toContain('view=');
        expect(searchRequestUrl).not.toContain('filter=');
    });

    it('activates search mode before network requests', async () => {
        mountDashboard();

        fetch.mockImplementationOnce(() => {
            expect(isDashboardSearchActive()).toBe(true);

            return Promise.resolve({
                ok: true,
                json: async () => ({
                    match_count: 0,
                    incident_ids: [],
                    results: [],
                }),
            });
        });

        await submitSearch('RD-EARLY-MODE');
    });

    it('shows search banner with result count', async () => {
        mountDashboard();

        fetch
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    match_count: 2,
                    incident_ids: [42, 43],
                    results: [{ incident_id: 42 }, { incident_id: 43 }],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    service_cases_empty: false,
                    rows: [
                        {
                            incident_id: 42,
                            html: '<tr id="service-case-row-42" data-incident-id="42"><td><a class="case-reference-link">SC00042</a></td><td>RD-A</td></tr>',
                        },
                        {
                            incident_id: 43,
                            html: '<tr id="service-case-row-43" data-incident-id="43"><td><a class="case-reference-link">SC00043</a></td><td>RD-B</td></tr>',
                        },
                    ],
                }),
            });

        await submitSearch('RD-MULTI');

        const banner = document.querySelector('[data-dashboard-search-banner]');
        expect(banner?.hidden).toBe(false);
        expect(banner?.querySelector('[data-dashboard-search-banner-title]')?.classList.contains('d-none')).toBe(false);
        expect(banner?.querySelector('[data-dashboard-search-banner-message]')?.textContent)
            .toBe('Showing 2 matching service cases');
    });

    it('shows zero-result banner without blanking dashboard rows', async () => {
        mountDashboard();

        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                match_count: 0,
                incident_ids: [],
                results: [],
            }),
        });

        await submitSearch('RD-NO-MATCH');

        const banner = document.querySelector('[data-dashboard-search-banner]');
        expect(banner?.hidden).toBe(false);
        expect(banner?.querySelector('[data-dashboard-search-banner-title]')?.classList.contains('d-none')).toBe(true);
        expect(banner?.querySelector('[data-dashboard-search-banner-message]')?.textContent)
            .toBe('No matching service cases found.');
        expect(document.getElementById('service-case-row-10')).not.toBeNull();
        expect(document.getElementById('service-case-row-20')).not.toBeNull();
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('prevents live dashboard refresh from overwriting search rows', async () => {
        mountDashboard();

        fetch
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    match_count: 1,
                    incident_ids: [42],
                    results: [{ incident_id: 42 }],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    service_cases_empty: false,
                    rows: [{
                        incident_id: 42,
                        html: '<tr id="service-case-row-42" data-incident-id="42"><td><a class="case-reference-link">SC00042</a></td><td>RD-MATCH-042</td></tr>',
                    }],
                }),
            });

        await submitSearch('RD-MATCH-042');

        expect(document.getElementById('service-case-row-42')).not.toBeNull();
        expect(document.getElementById('service-case-row-10')).toBeNull();

        await refreshDashboard(document.getElementById('dashboard-page'));

        expect(document.getElementById('service-case-row-42')).not.toBeNull();
        expect(document.getElementById('service-case-row-10')).toBeNull();
        expect(isDashboardSearchActive()).toBe(true);
    });

    it('clears search banner when search is cleared', async () => {
        mountDashboard();

        fetch
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    match_count: 0,
                    incident_ids: [],
                    results: [],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    service_cases_empty: false,
                    rows: [
                        {
                            incident_id: 10,
                            html: '<tr id="service-case-row-10" data-incident-id="10"><td><a class="case-reference-link">SC00010</a></td><td>RD-LOCAL-010</td></tr>',
                        },
                        {
                            incident_id: 20,
                            html: '<tr id="service-case-row-20" data-incident-id="20"><td><a class="case-reference-link">SC00020</a></td><td>RD-LOCAL-020</td></tr>',
                        },
                    ],
                }),
            });

        await submitSearch('RD-NO-MATCH');

        expect(document.querySelector('[data-dashboard-search-banner]')?.hidden).toBe(false);

        document.querySelector('[data-dashboard-search-clear]')?.click();

        await vi.waitFor(() => {
            expect(document.querySelector('[data-dashboard-search-banner]')?.hidden).toBe(true);
        });

        expect(isDashboardSearchActive()).toBe(false);
    });
});
