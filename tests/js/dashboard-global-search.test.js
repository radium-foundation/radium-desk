import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import * as bootstrap from 'bootstrap';
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

    const legacyConfirmModalHtml = `
            <div class="modal fade" id="legacySearchConfirmModal">
                <div class="modal-body">
                    <dd data-legacy-confirm-order-id></dd>
                    <dd data-legacy-confirm-customer-name></dd>
                    <dd data-legacy-confirm-mobile></dd>
                    <dd data-legacy-confirm-email></dd>
                    <dd data-legacy-confirm-product-model></dd>
                    <dd data-legacy-confirm-serial-number></dd>
                    <select id="legacy_search_confirm_source">
                        <option value="" disabled selected>Select source</option>
                        <option value="call">Call</option>
                        <option value="email">Email</option>
                    </select>
                    <input type="checkbox" id="legacy_search_confirm_high_priority" value="1">
                    <textarea id="legacy_search_confirm_notes"></textarea>
                    <div id="legacy_search_confirm_error" class="d-none"></div>
                </div>
                <button type="button" data-legacy-search-confirm-submit>Create Service Request</button>
            </div>
        `;

    const mountDashboard = ({ showToast = vi.fn(), includeLegacyConfirmModal = true } = {}) => {
        document.body.innerHTML = `
            <meta name="csrf-token" content="test-csrf-token">
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
            ${includeLegacyConfirmModal ? legacyConfirmModalHtml : ''}
        `;

        const pageRoot = document.getElementById('dashboard-page');
        const customer360Drawer = initCustomer360Drawer({
            pageRoot,
            showToast,
        });

        initUniversalSearch({
            showToast,
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

        return { pageRoot, customer360Drawer, showToast };
    };

    const jsonFetchResponse = (body, { ok = true, status = 200 } = {}) => ({
        ok,
        status,
        headers: {
            get: (name) => (name.toLowerCase() === 'content-type' ? 'application/json' : null),
        },
        json: async () => body,
    });

    const htmlFetchResponse = (status) => ({
        ok: false,
        status,
        headers: {
            get: (name) => (name.toLowerCase() === 'content-type' ? 'text/html; charset=UTF-8' : null),
        },
        json: async () => {
            throw new Error('Unexpected JSON parse');
        },
    });

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
            '/dashboard/live?queue=all&filter=all',
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
            .toBe('Showing results for RD-MULTI');
    });

    it('shows zero-result banner and clears dashboard rows', async () => {
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
            .toBe('No record found for RD-NO-MATCH');
        expect(document.querySelector('[data-dashboard-search-intake-fallback]')).toBeNull();
        expect(document.getElementById('service-case-row-10')).toBeNull();
        expect(document.getElementById('service-case-row-20')).toBeNull();
        expect(document.getElementById('dashboard-service-cases-empty-row')).not.toBeNull();
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('shows legacy intake fallback panel when search returns intake preview', async () => {
        mountDashboard();

        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                match_count: 0,
                incident_ids: [],
                results: [],
                intake: {
                    classification: 'legacy',
                    requires_confirmation: true,
                    legacy_preview_message: 'Legacy order found. Create service case?',
                    legacy_preview: {
                        order_id: 'RD3395988',
                        customer_name: 'Satyam Test',
                        mobile: '9876543210',
                        product_model: 'MFS 110',
                        serial_number: 'SN123456',
                    },
                    parsed_query: {
                        phone: null,
                        order_id: 'RD3395988',
                        serial_number: null,
                    },
                },
            }),
        });

        await submitSearch('RD3395988');

        const fallback = document.querySelector('[data-dashboard-search-intake-fallback]');
        expect(fallback).not.toBeNull();
        expect(document.querySelector('[data-dashboard-search-banner-message]')?.textContent)
            .toBe('Legacy order found — create service request');
        expect(fallback?.textContent).toContain('RD3395988');
        expect(fallback?.textContent).not.toContain('No record found');
        expect(fallback?.querySelector('[data-dashboard-search-intake-action]')?.textContent?.trim())
            .toBe('Create Service Request');
    });

    it('opens confirmation modal without creating on first click', async () => {
        const quickCreateShow = vi.fn();
        const legacyConfirmShow = vi.fn();
        vi.spyOn(bootstrap.Modal, 'getOrCreateInstance').mockImplementation((element) => ({
            show: element?.id === 'legacySearchConfirmModal' ? legacyConfirmShow : quickCreateShow,
            hide: vi.fn(),
        }));

        mountDashboard();

        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                match_count: 0,
                incident_ids: [],
                results: [],
                intake: {
                    classification: 'legacy',
                    requires_confirmation: true,
                    legacy_preview_complete: true,
                    default_source: 'call',
                    create_url: '/service-requests/quick',
                    legacy_preview: {
                        order_id: 'RD3395988',
                        customer_name: 'Satyam Test',
                        mobile: '9876543210',
                        email: 'test@example.com',
                        product_model: 'MFS 110',
                        serial_number: 'SN123456',
                    },
                    parsed_query: {
                        phone: null,
                        order_id: 'RD3395988',
                        serial_number: null,
                    },
                },
            }),
        });

        await submitSearch('RD3395988');
        document.querySelector('[data-dashboard-search-intake-action]')?.click();

        await vi.waitFor(() => {
            expect(legacyConfirmShow).toHaveBeenCalled();
        });

        expect(quickCreateShow).not.toHaveBeenCalled();
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(document.querySelector('[data-legacy-confirm-order-id]')?.textContent).toBe('RD3395988');
        expect(document.querySelector('#legacy_search_confirm_source')?.value).toBe('call');
    });

    it('creates complete legacy orders after confirmation without opening quick create', async () => {
        const quickCreateShow = vi.fn();
        const legacyConfirmShow = vi.fn();
        vi.spyOn(bootstrap.Modal, 'getOrCreateInstance').mockImplementation((element) => ({
            show: element?.id === 'legacySearchConfirmModal' ? legacyConfirmShow : quickCreateShow,
            hide: vi.fn(),
        }));

        const { customer360Drawer, showToast } = mountDashboard();
        const openDrawerSpy = vi.spyOn(customer360Drawer, 'open');

        fetch
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    match_count: 0,
                    incident_ids: [],
                    results: [],
                    intake: {
                        classification: 'legacy',
                        requires_confirmation: true,
                        legacy_preview_complete: true,
                        default_source: 'call',
                        create_url: '/service-requests/quick',
                        legacy_preview: {
                            order_id: 'RD3395988',
                            customer_name: 'Satyam Test',
                            mobile: '9876543210',
                            product_model: 'MFS 110',
                            serial_number: 'SN123456',
                        },
                        parsed_query: {
                            phone: null,
                            order_id: 'RD3395988',
                            serial_number: null,
                        },
                    },
                }),
            })
            .mockResolvedValueOnce(jsonFetchResponse({
                message: 'Service Case SC00055 created',
                incident_id: 55,
                display_reference: 'SC00055',
            }))
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    match_count: 1,
                    incident_ids: [55],
                    results: [{
                        type: 'service_case',
                        service_case: 'SC00055',
                        order_id: 'RD3395988',
                        status: 'Open',
                        actions: {
                            incident_id: 55,
                            display_reference: 'SC00055',
                        },
                    }],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    rows: ['<tr id="service-case-row-55" data-incident-id="55"><td><a class="case-reference-link">SC00055</a></td><td>RD3395988</td></tr>'],
                    service_cases_empty: false,
                }),
            });

        await submitSearch('RD3395988');

        document.querySelector('[data-dashboard-search-intake-action]')?.click();

        await vi.waitFor(() => {
            expect(legacyConfirmShow).toHaveBeenCalled();
        });

        const notesField = document.getElementById('legacy_search_confirm_notes');
        const sourceField = document.getElementById('legacy_search_confirm_source');
        const highPriorityField = document.getElementById('legacy_search_confirm_high_priority');

        if (notesField) {
            notesField.value = 'Screen flickering issue';
        }

        if (sourceField) {
            sourceField.value = 'email';
        }

        if (highPriorityField) {
            highPriorityField.checked = true;
        }

        document.querySelector('[data-legacy-search-confirm-submit]')?.click();

        await vi.waitFor(() => {
            expect(showToast).toHaveBeenCalledWith('Service Case SC00055 created');
        });

        expect(quickCreateShow).not.toHaveBeenCalled();
        expect(openDrawerSpy).toHaveBeenCalledWith(55, 'SC00055');

        const createRequest = fetch.mock.calls.find(
            ([url, options]) => url === '/service-requests/quick' && options?.method === 'POST',
        );
        expect(createRequest).toBeDefined();
        expect(createRequest?.[1]?.headers?.Accept).toBe('application/json');
        expect(createRequest?.[1]?.body.get('source')).toBe('email');
        expect(createRequest?.[1]?.body.get('notes')).toBe('Screen flickering issue');
        expect(createRequest?.[1]?.body.get('high_priority')).toBe('1');
    });

    it('shows toast when confirmed legacy create is rejected as duplicate', async () => {
        const legacyConfirmShow = vi.fn();
        vi.spyOn(bootstrap.Modal, 'getOrCreateInstance').mockImplementation((element) => ({
            show: element?.id === 'legacySearchConfirmModal' ? legacyConfirmShow : vi.fn(),
            hide: vi.fn(),
        }));

        const { showToast } = mountDashboard();

        fetch
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    match_count: 0,
                    incident_ids: [],
                    results: [],
                    intake: {
                        classification: 'legacy',
                        requires_confirmation: true,
                        legacy_preview_complete: true,
                        default_source: 'call',
                        create_url: '/service-requests/quick',
                        legacy_preview: {
                            order_id: 'RD3395988',
                            customer_name: 'Satyam Test',
                            mobile: '9876543210',
                            product_model: 'MFS 110',
                            serial_number: 'SN123456',
                        },
                        parsed_query: {
                            phone: null,
                            order_id: 'RD3395988',
                            serial_number: null,
                        },
                    },
                }),
            })
            .mockResolvedValueOnce({
                ok: false,
                status: 422,
                headers: {
                    get: (name) => (name.toLowerCase() === 'content-type' ? 'application/json' : null),
                },
                json: async () => ({
                    message: 'The given data was invalid.',
                    errors: {
                        legacy_order_id: ['This order already exists in Radium Desk.'],
                    },
                }),
            });

        await submitSearch('RD3395988');
        document.querySelector('[data-dashboard-search-intake-action]')?.click();

        await vi.waitFor(() => {
            expect(legacyConfirmShow).toHaveBeenCalled();
        });

        document.querySelector('[data-legacy-search-confirm-submit]')?.click();

        await vi.waitFor(() => {
            expect(showToast).toHaveBeenCalledWith(
                'This order already exists in Radium Desk.',
                'danger',
            );
        });

        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('shows session expired toast when confirmed legacy create returns non-json 419', async () => {
        const legacyConfirmShow = vi.fn();
        vi.spyOn(bootstrap.Modal, 'getOrCreateInstance').mockImplementation((element) => ({
            show: element?.id === 'legacySearchConfirmModal' ? legacyConfirmShow : vi.fn(),
            hide: vi.fn(),
        }));

        const { showToast } = mountDashboard();

        fetch
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    match_count: 0,
                    incident_ids: [],
                    results: [],
                    intake: {
                        classification: 'legacy',
                        requires_confirmation: true,
                        legacy_preview_complete: true,
                        default_source: 'call',
                        create_url: '/service-requests/quick',
                        legacy_preview: {
                            order_id: 'RD3395988',
                            customer_name: 'Satyam Test',
                            mobile: '9876543210',
                            product_model: 'MFS 110',
                            serial_number: 'SN123456',
                        },
                        parsed_query: {
                            phone: null,
                            order_id: 'RD3395988',
                            serial_number: null,
                        },
                    },
                }),
            })
            .mockResolvedValueOnce(htmlFetchResponse(419));

        await submitSearch('RD3395988');
        document.querySelector('[data-dashboard-search-intake-action]')?.click();

        await vi.waitFor(() => {
            expect(legacyConfirmShow).toHaveBeenCalled();
        });

        document.querySelector('[data-legacy-search-confirm-submit]')?.click();

        await vi.waitFor(() => {
            expect(showToast).toHaveBeenCalledWith(
                'Session expired. Refresh and try again.',
                'danger',
            );
        });
    });

    it('shows generic toast when confirmed legacy create returns non-json error', async () => {
        const legacyConfirmShow = vi.fn();
        vi.spyOn(bootstrap.Modal, 'getOrCreateInstance').mockImplementation((element) => ({
            show: element?.id === 'legacySearchConfirmModal' ? legacyConfirmShow : vi.fn(),
            hide: vi.fn(),
        }));

        const { showToast } = mountDashboard();

        fetch
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    match_count: 0,
                    incident_ids: [],
                    results: [],
                    intake: {
                        classification: 'legacy',
                        requires_confirmation: true,
                        legacy_preview_complete: true,
                        default_source: 'call',
                        create_url: '/service-requests/quick',
                        legacy_preview: {
                            order_id: 'RD3395988',
                            customer_name: 'Satyam Test',
                            mobile: '9876543210',
                            product_model: 'MFS 110',
                            serial_number: 'SN123456',
                        },
                        parsed_query: {
                            phone: null,
                            order_id: 'RD3395988',
                            serial_number: null,
                        },
                    },
                }),
            })
            .mockResolvedValueOnce(htmlFetchResponse(500));

        await submitSearch('RD3395988');
        document.querySelector('[data-dashboard-search-intake-action]')?.click();

        await vi.waitFor(() => {
            expect(legacyConfirmShow).toHaveBeenCalled();
        });

        document.querySelector('[data-legacy-search-confirm-submit]')?.click();

        await vi.waitFor(() => {
            expect(showToast).toHaveBeenCalledWith(
                'Unable to create service request.',
                'danger',
            );
        });
    });

    it('falls back to quick create for incomplete legacy preview', async () => {
        const quickCreateShow = vi.fn();
        vi.spyOn(bootstrap.Modal, 'getOrCreateInstance').mockReturnValue({
            show: quickCreateShow,
        });

        mountDashboard();

        document.body.insertAdjacentHTML('beforeend', `
            <div class="modal" id="quickCreateModal">
                <form id="customerIntakeForm">
                    <input id="intake_phone" type="text">
                    <input id="intake_order_id" type="text">
                    <input id="intake_serial_number" type="text">
                    <div id="intake-search-feedback" class="alert d-none"></div>
                    <button type="button" id="intake-search-button">Search</button>
                </form>
            </div>
        `);

        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                match_count: 0,
                incident_ids: [],
                results: [],
                intake: {
                    classification: 'legacy',
                    requires_confirmation: true,
                    legacy_preview_complete: false,
                    missing_fields: ['serial_number'],
                    legacy_preview: {
                        order_id: 'RD3395988',
                        customer_name: 'Satyam Test',
                        mobile: '9876543210',
                        product_model: 'MFS 110',
                        serial_number: null,
                    },
                    parsed_query: {
                        phone: null,
                        order_id: 'RD3395988',
                        serial_number: null,
                    },
                },
            }),
        });

        await submitSearch('RD3395988');
        document.querySelector('[data-dashboard-search-intake-action]')?.click();

        await vi.waitFor(() => {
            expect(quickCreateShow).toHaveBeenCalled();
        });

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(document.getElementById('intake_order_id')?.value).toBe('RD3395988');
    });

    it('shows new contact intake fallback panel for unknown queries', async () => {
        mountDashboard();

        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                match_count: 0,
                incident_ids: [],
                results: [],
                intake: {
                    classification: 'new_contact',
                    requires_confirmation: false,
                    legacy_preview: null,
                    parsed_query: {
                        phone: null,
                        order_id: null,
                        serial_number: null,
                        email: null,
                    },
                },
            }),
        });

        await submitSearch('Unknown Customer Name');

        const fallback = document.querySelector('[data-dashboard-search-intake-fallback]');
        expect(fallback).not.toBeNull();
        expect(document.querySelector('[data-dashboard-search-banner-message]')?.textContent)
            .toBe('No existing record — create new service request');
        expect(fallback?.textContent).not.toContain('No desk record found');
        expect(fallback?.querySelector('[data-dashboard-search-intake-action]')).not.toBeNull();
    });

    it('opens quick create modal from intake fallback action', async () => {
        const quickCreateShow = vi.fn();
        vi.spyOn(bootstrap.Modal, 'getOrCreateInstance').mockReturnValue({
            show: quickCreateShow,
        });

        document.body.innerHTML = `
            <meta name="csrf-token" content="test-csrf-token">
            <form data-universal-search-form data-search-url="/search">
                <input id="global-search-input" type="search" value="">
            </form>
            <div id="dashboard-page" data-dashboard-search-rows-url="/dashboard/service-cases/search-rows">
                <div class="dashboard-service-cases-card">
                    <div id="dashboard-service-cases-content">
                        <div class="dashboard-search-banner d-none"
                             data-dashboard-search-banner
                             hidden>
                            <div class="dashboard-search-banner__content">
                                <strong data-dashboard-search-banner-title>Search Results</strong>
                                <p data-dashboard-search-banner-message></p>
                                <button type="button" data-dashboard-search-clear>Clear Search</button>
                            </div>
                        </div>
                        <div id="dashboard-service-cases-scroll">
                            <table><tbody id="dashboard-service-cases-body"></tbody></table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal" id="quickCreateModal">
                <form id="customerIntakeForm">
                    <input id="intake_phone" type="text">
                    <input id="intake_order_id" type="text">
                    <input id="intake_serial_number" type="text">
                    <div id="intake-search-feedback" class="alert d-none"></div>
                    <button type="button" id="intake-search-button">Search</button>
                </form>
            </div>
        `;

        const pageRoot = document.getElementById('dashboard-page');
        const intakeSearchClick = vi.fn();
        document.getElementById('intake-search-button')?.addEventListener('click', intakeSearchClick);

        initUniversalSearch({
            dashboardIntegration: {
                pageRoot,
                searchRowsUrl: pageRoot.dataset.dashboardSearchRowsUrl,
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
                intake: {
                    classification: 'new_contact',
                    parsed_query: {
                        phone: null,
                        order_id: null,
                        serial_number: null,
                        email: null,
                    },
                },
            }),
        });

        document.getElementById('global-search-input').value = 'Unknown Customer Name';
        document.querySelector('[data-universal-search-form]')?.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }),
        );

        await vi.waitFor(() => {
            expect(document.querySelector('[data-dashboard-search-intake-fallback]')).not.toBeNull();
        });

        document.querySelector('[data-dashboard-search-intake-action]')?.click();

        await vi.waitFor(() => {
            expect(quickCreateShow).toHaveBeenCalled();
        });

        expect(document.getElementById('intake_order_id')?.value).toBe('Unknown Customer Name');
        expect(intakeSearchClick).not.toHaveBeenCalled();
    });

    it('prefills phone field and auto-runs quick create search from parsed query', async () => {
        const quickCreateShow = vi.fn();
        vi.spyOn(bootstrap.Modal, 'getOrCreateInstance').mockReturnValue({
            show: quickCreateShow,
        });

        document.body.innerHTML = `
            <form data-universal-search-form data-search-url="/search">
                <input id="global-search-input" type="search" value="">
            </form>
            <div id="dashboard-page" data-dashboard-search-rows-url="/dashboard/service-cases/search-rows">
                <div class="dashboard-service-cases-card">
                    <div class="dashboard-search-banner d-none" data-dashboard-search-banner hidden>
                        <p data-dashboard-search-banner-message></p>
                    </div>
                    <div id="dashboard-service-cases-scroll">
                        <table><tbody id="dashboard-service-cases-body"></tbody></table>
                    </div>
                </div>
            </div>
            <div class="modal" id="quickCreateModal">
                <form id="customerIntakeForm">
                    <input id="intake_phone" type="text">
                    <input id="intake_order_id" type="text">
                    <input id="intake_serial_number" type="text">
                    <div id="intake-search-feedback" class="alert d-none"></div>
                    <button type="button" id="intake-search-button">Search</button>
                </form>
            </div>
        `;

        const pageRoot = document.getElementById('dashboard-page');
        const intakeSearchClick = vi.fn();
        document.getElementById('intake-search-button')?.addEventListener('click', intakeSearchClick);

        initUniversalSearch({
            dashboardIntegration: {
                pageRoot,
                searchRowsUrl: pageRoot.dataset.dashboardSearchRowsUrl,
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
                intake: {
                    classification: 'new_contact',
                    parsed_query: {
                        phone: '9876543210',
                        order_id: null,
                        serial_number: null,
                        email: null,
                    },
                },
            }),
        });

        document.getElementById('global-search-input').value = '9876543210';
        document.querySelector('[data-universal-search-form]')?.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }),
        );

        await vi.waitFor(() => {
            expect(document.querySelector('[data-dashboard-search-intake-fallback]')).not.toBeNull();
        });

        document.querySelector('[data-dashboard-search-intake-action]')?.click();

        await vi.waitFor(() => {
            expect(intakeSearchClick).toHaveBeenCalled();
        });

        expect(document.getElementById('intake_phone')?.value).toBe('9876543210');
        expect(document.getElementById('intake_order_id')?.value).toBe('');
    });

    it('refreshes search results after successful reopen action', async () => {
        mountDashboard();

        fetch
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    match_count: 1,
                    incident_ids: [43],
                    results: [{
                        type: 'service_case',
                        incident_id: 43,
                        service_case: 'SC00043',
                        order_id: 'RD-CLOSED-001',
                        customer: 'Closed Customer',
                        status: 'Closed',
                        actions: {
                            incident_id: 43,
                            display_reference: 'SC00043',
                            is_closed: true,
                            can_reopen: true,
                            reopen_url: '/incidents/43/workspace/action',
                            reopen_workspace_context: 'service_case',
                        },
                    }],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    service_cases_empty: false,
                    rows: [{
                        incident_id: 43,
                        html: '<tr id="service-case-row-43" data-incident-id="43"><td><a class="case-reference-link">SC00043</a></td><td>RD-CLOSED-001</td></tr>',
                    }],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                text: async () => '<div data-customer-360-content>Drawer loaded</div>',
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ success: true }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    match_count: 1,
                    incident_ids: [43],
                    results: [{
                        type: 'service_case',
                        incident_id: 43,
                        service_case: 'SC00043',
                        order_id: 'RD-CLOSED-001',
                        customer: 'Closed Customer',
                        status: 'Open',
                        actions: {
                            incident_id: 43,
                            display_reference: 'SC00043',
                            is_closed: false,
                            can_reopen: false,
                            reopen_url: null,
                            reopen_workspace_context: 'service_case',
                        },
                    }],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    service_cases_empty: false,
                    rows: [{
                        incident_id: 43,
                        html: '<tr id="service-case-row-43" data-incident-id="43"><td><a class="case-reference-link">SC00043</a></td><td>RD-CLOSED-001</td></tr>',
                    }],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                text: async () => '<div data-customer-360-content>Drawer refreshed</div>',
            });

        const refreshHandler = vi.fn();
        document.addEventListener('customer360:refresh', refreshHandler);

        await submitSearch('RD-CLOSED-001');

        document.querySelector('[data-search-reopen-case]')?.click();

        await vi.waitFor(() => {
            const searchCalls = fetch.mock.calls.filter(([url]) => String(url).includes('/search?q=RD-CLOSED-001'));
            expect(searchCalls.length).toBeGreaterThanOrEqual(2);
        });

        await vi.waitFor(() => {
            expect(document.querySelector('[data-search-reopen-case]')).toBeNull();
            expect(document.querySelector('[data-search-result-action]')?.textContent).toContain('Open');
        });

        expect(refreshHandler).toHaveBeenCalled();
    });

    it('bootstraps search from dashboard q query parameter', async () => {
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
                                <td><a href="/incidents/42" class="case-reference-link">SC03587</a></td>
                                <td>RD3437143</td>
                            </tr>
                        `,
                    }],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                text: async () => '<div data-customer-360-content>Drawer loaded</div>',
            });

        window.history.replaceState({}, '', '/dashboard?q=RD3437143');

        mountDashboard();

        await vi.waitFor(() => {
            expect(fetch).toHaveBeenCalledWith(
                '/search?q=RD3437143',
                expect.objectContaining({
                    headers: expect.objectContaining({ Accept: 'application/json' }),
                }),
            );
        });

        await vi.waitFor(() => {
            expect(document.getElementById('service-case-row-42')).not.toBeNull();
            expect(document.getElementById('service-case-row-10')).toBeNull();
        });

        expect(document.getElementById('global-search-input')?.value).toBe('RD3437143');
        expect(document.querySelector('[data-dashboard-search-banner-message]')?.textContent)
            .toBe('Showing results for RD3437143');
    });

    it('shows visible error when search fetch fails', async () => {
        mountDashboard();

        fetch.mockResolvedValueOnce({
            ok: false,
            json: async () => ({}),
        });

        await submitSearch('RD-FAIL');

        const banner = document.querySelector('[data-dashboard-search-banner]');
        expect(banner?.hidden).toBe(false);
        expect(banner?.classList.contains('dashboard-search-banner--error')).toBe(true);
        expect(banner?.querySelector('[data-dashboard-search-banner-message]')?.textContent)
            .toBe('Unable to load search results. Please try again.');
    });

    it('shows visible error when search rows fetch fails', async () => {
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
                ok: false,
                json: async () => ({}),
            });

        await submitSearch('RD-ROWS-FAIL');

        const banner = document.querySelector('[data-dashboard-search-banner]');
        expect(banner?.hidden).toBe(false);
        expect(banner?.classList.contains('dashboard-search-banner--error')).toBe(true);
        expect(banner?.querySelector('[data-dashboard-search-banner-message]')?.textContent)
            .toBe('Unable to load matching service cases. Please try again.');
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
                    intake: {
                        classification: 'new_contact',
                        parsed_query: {
                            phone: null,
                            order_id: null,
                            serial_number: null,
                        },
                    },
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
        expect(document.querySelector('[data-dashboard-search-intake-fallback]')).not.toBeNull();

        document.querySelector('[data-dashboard-search-clear]')?.click();

        await vi.waitFor(() => {
            expect(document.querySelector('[data-dashboard-search-banner]')?.hidden).toBe(true);
        });

        expect(document.querySelector('[data-dashboard-search-intake-fallback]')).toBeNull();
        expect(isDashboardSearchActive()).toBe(false);
    });

    it('renders desk action buttons for search results', async () => {
        mountDashboard();

        fetch
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    match_count: 2,
                    incident_ids: [42, 43],
                    results: [
                        {
                            type: 'service_case',
                            incident_id: 42,
                            service_case: 'SC00042',
                            order_id: 'RD-ORDER-A',
                            customer: 'Customer A',
                            status: 'Open',
                            actions: {
                                incident_id: 42,
                                display_reference: 'SC00042',
                                is_closed: false,
                                can_reopen: false,
                                reopen_url: null,
                                reopen_workspace_context: 'service_case',
                            },
                        },
                        {
                            type: 'service_case',
                            incident_id: 43,
                            service_case: 'SC00043',
                            order_id: 'RD-ORDER-B',
                            customer: 'Customer B',
                            status: 'Closed',
                            actions: {
                                incident_id: 43,
                                display_reference: 'SC00043',
                                is_closed: true,
                                can_reopen: true,
                                reopen_url: '/incidents/43/workspace/action',
                                reopen_workspace_context: 'service_case',
                            },
                        },
                    ],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    service_cases_empty: false,
                    rows: [
                        {
                            incident_id: 42,
                            html: '<tr id="service-case-row-42" data-incident-id="42"><td><a class="case-reference-link">SC00042</a></td><td>RD-ORDER-A</td></tr>',
                        },
                        {
                            incident_id: 43,
                            html: '<tr id="service-case-row-43" data-incident-id="43"><td><a class="case-reference-link">SC00043</a></td><td>RD-ORDER-B</td></tr>',
                        },
                    ],
                }),
            });

        await submitSearch('RD-MULTI');

        const panel = document.querySelector('[data-dashboard-search-result-actions]');
        expect(panel).not.toBeNull();
        expect(panel?.querySelectorAll('[data-search-open-customer-360]')).toHaveLength(2);
        expect(panel?.querySelectorAll('[data-search-reopen-case]')).toHaveLength(1);
        expect(panel?.querySelector('[data-search-open-customer-360]')?.textContent?.trim())
            .toBe('Customer 360');
    });

    it('opens customer 360 from search action button', async () => {
        mountDashboard();

        fetch
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    match_count: 2,
                    incident_ids: [42, 43],
                    results: [
                        {
                            type: 'service_case',
                            incident_id: 42,
                            service_case: 'SC00042',
                            order_id: 'RD-ORDER-A',
                            customer: 'Customer A',
                            status: 'Open',
                            actions: {
                                incident_id: 42,
                                display_reference: 'SC00042',
                                is_closed: false,
                                can_reopen: false,
                                reopen_url: null,
                                reopen_workspace_context: 'service_case',
                            },
                        },
                        {
                            type: 'service_case',
                            incident_id: 43,
                            service_case: 'SC00043',
                            order_id: 'RD-ORDER-B',
                            customer: 'Customer B',
                            status: 'Closed',
                            actions: {
                                incident_id: 43,
                                display_reference: 'SC00043',
                                is_closed: true,
                                can_reopen: true,
                                reopen_url: '/incidents/43/workspace/action',
                                reopen_workspace_context: 'service_case',
                            },
                        },
                    ],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    service_cases_empty: false,
                    rows: [
                        {
                            incident_id: 42,
                            html: '<tr id="service-case-row-42" data-incident-id="42"><td><a class="case-reference-link">SC00042</a></td><td>RD-ORDER-A</td></tr>',
                        },
                        {
                            incident_id: 43,
                            html: '<tr id="service-case-row-43" data-incident-id="43"><td><a class="case-reference-link">SC00043</a></td><td>RD-ORDER-B</td></tr>',
                        },
                    ],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                text: async () => '<div data-customer-360-content>Drawer loaded</div>',
            });

        await submitSearch('RD-MULTI');

        document.querySelectorAll('[data-search-open-customer-360]')[1]?.click();

        await vi.waitFor(() => {
            expect(document.querySelector('[data-customer-360-drawer]')?.classList.contains('is-open')).toBe(true);
        });

        expect(fetch).toHaveBeenCalledWith(
            'http://localhost/dashboard/service-cases/43/customer-360',
            expect.any(Object),
        );
    });

    it('calls reopen workflow from search action button', async () => {
        mountDashboard();

        fetch
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    match_count: 1,
                    incident_ids: [43],
                    results: [{
                        type: 'service_case',
                        incident_id: 43,
                        service_case: 'SC00043',
                        order_id: 'RD-CLOSED-001',
                        customer: 'Closed Customer',
                        status: 'Closed',
                        actions: {
                            incident_id: 43,
                            display_reference: 'SC00043',
                            is_closed: true,
                            can_reopen: true,
                            reopen_url: '/incidents/43/workspace/action',
                            reopen_workspace_context: 'service_case',
                        },
                    }],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    service_cases_empty: false,
                    rows: [{
                        incident_id: 43,
                        html: '<tr id="service-case-row-43" data-incident-id="43"><td><a class="case-reference-link">SC00043</a></td><td>RD-CLOSED-001</td></tr>',
                    }],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ success: true }),
            })
            .mockResolvedValueOnce({
                ok: true,
                text: async () => '<div data-customer-360-content>Drawer loaded</div>',
            });

        await submitSearch('RD-CLOSED-001');

        document.querySelector('[data-search-reopen-case]')?.click();

        await vi.waitFor(() => {
            expect(fetch).toHaveBeenCalledWith(
                '/incidents/43/workspace/action',
                expect.objectContaining({
                    method: 'PATCH',
                    body: JSON.stringify({
                        workspace_context: 'service_case',
                        action_type: 'reopen',
                        body: 'Reopened from global search.',
                    }),
                }),
            );
        });
    });

    it('clears desk action panel when search is cleared', async () => {
        mountDashboard();

        fetch
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    match_count: 1,
                    incident_ids: [42],
                    results: [{
                        type: 'service_case',
                        incident_id: 42,
                        service_case: 'SC00042',
                        order_id: 'RD-ORDER-A',
                        customer: 'Customer A',
                        status: 'Open',
                        actions: {
                            incident_id: 42,
                            display_reference: 'SC00042',
                            is_closed: false,
                            can_reopen: false,
                            reopen_url: null,
                            reopen_workspace_context: 'service_case',
                        },
                    }],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    service_cases_empty: false,
                    rows: [{
                        incident_id: 42,
                        html: '<tr id="service-case-row-42" data-incident-id="42"><td><a class="case-reference-link">SC00042</a></td><td>RD-ORDER-A</td></tr>',
                    }],
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                text: async () => '<div data-customer-360-content>Drawer loaded</div>',
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

        await submitSearch('RD-ORDER-A');

        expect(document.querySelector('[data-dashboard-search-result-actions]')).not.toBeNull();

        document.querySelector('[data-dashboard-search-clear]')?.click();

        await vi.waitFor(() => {
            expect(document.querySelector('[data-dashboard-search-result-actions]')).toBeNull();
        });
    });
});
