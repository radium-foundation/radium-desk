import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initUniversalSearch } from '../../resources/js/universal-search';
import { initCustomer360Drawer } from '../../resources/js/customer-360-drawer';
import { resetDashboardSearchMode } from '../../resources/js/dashboard-search-mode';
import { resetWorkspaceSession } from '../../resources/js/workspace/session';
import {
    SESSION_LOGOUT_STORAGE_KEY,
    resetSessionLifecycleForTests,
} from '../../resources/js/core/session-lifecycle';

const stubSessionLifecycleDom = () => {
    document.body.dataset.sessionLifecycle = 'true';
    document.body.dataset.sessionStatusUrl = '/session/status';
};

const stubBroadcast = () => {
    const postMessage = vi.fn();
    vi.stubGlobal('BroadcastChannel', vi.fn(() => ({
        postMessage,
        addEventListener: vi.fn(),
        close: vi.fn(),
    })));

    return postMessage;
};

const buildUniversalSearchDom = () => {
    document.body.innerHTML = `
        <form data-universal-search-form data-search-url="/search">
            <input id="global-search-input" type="search" value="">
        </form>
        <div id="dashboard-page">
            <div class="dashboard-service-cases-card">
                <div class="dashboard-search-banner d-none"
                     data-dashboard-search-banner
                     hidden>
                    <strong data-dashboard-search-banner-title></strong>
                    <p data-dashboard-search-banner-message></p>
                </div>
                <div id="dashboard-service-cases-scroll">
                    <table><tbody id="dashboard-service-cases-body"></tbody></table>
                </div>
            </div>
        </div>
    `;

    return {
        pageRoot: document.getElementById('dashboard-page'),
        input: document.getElementById('global-search-input'),
        applyRows: vi.fn(),
    };
};

const triggerUniversalSearch = (input) => {
    input.value = 'RD9001';
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
};

const setupCustomer360Dashboard = () => {
    document.body.innerHTML = `
        <div id="dashboard-page" data-customer-360-url="http://localhost/dashboard/service-cases">
            <table>
                <tbody>
                    <tr data-incident-id="42">
                        <td><a href="/incidents/42" class="case-reference-link">SC-001</a></td>
                        <td class="case-order-cell"><a href="/orders/99">RD-001</a></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div data-customer-360-drawer aria-hidden="true">
            <div data-customer-360-backdrop></div>
            <aside data-customer-360-panel>
                <div data-customer-360-error class="d-none"></div>
                <div data-customer-360-loading hidden></div>
                <div class="customer-360-drawer-body" data-customer-360-body>
                    <div data-customer-360-content-host></div>
                </div>
            </aside>
        </div>
    `;

    return document.getElementById('dashboard-page');
};

describe('universal-search.js entry-point auth integration', () => {
    beforeEach(() => {
        resetSessionLifecycleForTests();
        resetDashboardSearchMode();
        stubSessionLifecycleDom();
        window.location.replace = vi.fn();
        window.location.reload = vi.fn();
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        document.body.innerHTML = '';
        resetDashboardSearchMode();
        window.sessionStorage.clear();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('401 on search fetch redirects via replace and broadcasts logout', async () => {
        const postMessage = stubBroadcast();
        const { pageRoot, input, applyRows } = buildUniversalSearchDom();

        fetch.mockResolvedValueOnce(new Response(null, { status: 401 }));

        initUniversalSearch({
            dashboardIntegration: {
                pageRoot,
                searchRowsUrl: '/dashboard/service-cases/search-rows',
                applyRows,
                restoreDashboard: vi.fn(),
            },
        });

        triggerUniversalSearch(input);

        await vi.waitFor(() => {
            expect(window.location.replace).toHaveBeenCalledWith('/login');
        });

        expect(postMessage).toHaveBeenCalledWith(expect.objectContaining({ type: 'logout' }));
        expect(applyRows).not.toHaveBeenCalled();
        expect(window.location.replace).toHaveBeenCalledTimes(1);
    });

    it('403 on search fetch does not redirect or broadcast', async () => {
        const postMessage = stubBroadcast();
        const { pageRoot, input, applyRows } = buildUniversalSearchDom();

        fetch.mockResolvedValueOnce(new Response(null, { status: 403 }));

        initUniversalSearch({
            dashboardIntegration: {
                pageRoot,
                searchRowsUrl: '/dashboard/service-cases/search-rows',
                applyRows,
                restoreDashboard: vi.fn(),
            },
        });

        triggerUniversalSearch(input);

        await vi.waitFor(() => {
            expect(
                document.querySelector('[data-dashboard-search-banner-message]')?.textContent,
            ).toContain('Unable to load search results');
        });

        expect(window.location.replace).not.toHaveBeenCalled();
        expect(postMessage).not.toHaveBeenCalled();
        expect(applyRows).not.toHaveBeenCalled();
    });

    it('419 probes session status and reloads once when still authenticated', async () => {
        stubBroadcast();
        const { pageRoot, input, applyRows } = buildUniversalSearchDom();

        fetch
            .mockResolvedValueOnce(new Response(null, { status: 419 }))
            .mockResolvedValueOnce(
                new Response(JSON.stringify({ authenticated: true }), { status: 200 }),
            );

        initUniversalSearch({
            dashboardIntegration: {
                pageRoot,
                searchRowsUrl: '/dashboard/service-cases/search-rows',
                applyRows,
                restoreDashboard: vi.fn(),
            },
        });

        triggerUniversalSearch(input);

        await vi.waitFor(() => {
            expect(fetch).toHaveBeenCalledWith(
                '/session/status',
                expect.objectContaining({ method: 'GET' }),
            );
        });

        expect(window.location.reload).toHaveBeenCalledTimes(1);
        expect(window.location.replace).not.toHaveBeenCalled();
    });

    it('419 redirects only when probe confirms unauthenticated', async () => {
        stubBroadcast();
        const { pageRoot, input } = buildUniversalSearchDom();

        fetch
            .mockResolvedValueOnce(new Response(null, { status: 419 }))
            .mockResolvedValueOnce(new Response(null, { status: 401 }));

        initUniversalSearch({
            dashboardIntegration: {
                pageRoot,
                searchRowsUrl: '/dashboard/service-cases/search-rows',
                applyRows: vi.fn(),
                restoreDashboard: vi.fn(),
            },
        });

        triggerUniversalSearch(input);

        await vi.waitFor(() => {
            expect(window.location.replace).toHaveBeenCalledWith('/login');
        });

        expect(window.location.reload).not.toHaveBeenCalled();
    });

    it('network failure shows search error without logout', async () => {
        const postMessage = stubBroadcast();
        const { pageRoot, input } = buildUniversalSearchDom();

        fetch.mockRejectedValueOnce(new Error('offline'));

        initUniversalSearch({
            dashboardIntegration: {
                pageRoot,
                searchRowsUrl: '/dashboard/service-cases/search-rows',
                applyRows: vi.fn(),
                restoreDashboard: vi.fn(),
            },
        });

        triggerUniversalSearch(input);

        await vi.waitFor(() => {
            expect(
                document.querySelector('[data-dashboard-search-banner-message]')?.textContent,
            ).toContain('Unable to load search results');
        });

        expect(window.location.replace).not.toHaveBeenCalled();
        expect(postMessage).not.toHaveBeenCalled();
    });
});

describe('customer-360-drawer.js entry-point auth integration', () => {
    beforeEach(() => {
        resetSessionLifecycleForTests();
        resetWorkspaceSession();
        stubSessionLifecycleDom();
        window.location.replace = vi.fn();
        window.location.reload = vi.fn();
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        document.body.innerHTML = '';
        document.body.classList.remove('customer-360-drawer-open');
        resetWorkspaceSession();
        window.sessionStorage.clear();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('401 on initial drawer load redirects and does not treat as permission denied', async () => {
        const postMessage = stubBroadcast();
        const pageRoot = setupCustomer360Dashboard();

        fetch.mockResolvedValueOnce(new Response(null, { status: 401 }));

        initCustomer360Drawer({ pageRoot });
        document.querySelector('tr[data-incident-id="42"]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        await vi.waitFor(() => {
            expect(window.location.replace).toHaveBeenCalledWith('/login');
        });

        expect(fetch).toHaveBeenCalledWith(
            '/dashboard/service-cases/42/customer-360',
            expect.objectContaining({ method: 'GET' }),
        );
        expect(postMessage).toHaveBeenCalledWith(expect.objectContaining({ type: 'logout' }));
        expect(document.querySelector('[data-customer-360-content-host]')?.innerHTML).toBe('');
    });

    it('403 on initial drawer load stays on page without logout broadcast', async () => {
        const postMessage = stubBroadcast();
        const pageRoot = setupCustomer360Dashboard();

        fetch.mockResolvedValueOnce(new Response(null, { status: 403 }));

        initCustomer360Drawer({ pageRoot });
        document.querySelector('tr[data-incident-id="42"]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        await vi.waitFor(() => {
            expect(document.querySelector('[data-customer-360-error]')?.classList.contains('d-none')).toBe(false);
        });

        expect(window.location.replace).not.toHaveBeenCalled();
        expect(postMessage).not.toHaveBeenCalled();
        expect(window.localStorage.getItem(SESSION_LOGOUT_STORAGE_KEY)).toBeNull();
    });

    it('419 on drawer load probes before reload when session is still valid', async () => {
        stubBroadcast();
        const pageRoot = setupCustomer360Dashboard();

        fetch
            .mockResolvedValueOnce(new Response(null, { status: 419 }))
            .mockResolvedValueOnce(
                new Response(JSON.stringify({ authenticated: true }), { status: 200 }),
            );

        initCustomer360Drawer({ pageRoot });
        document.querySelector('tr[data-incident-id="42"]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        await vi.waitFor(() => {
            expect(window.location.reload).toHaveBeenCalledTimes(1);
        });

        expect(fetch).toHaveBeenCalledWith('/session/status', expect.any(Object));
        expect(window.location.replace).not.toHaveBeenCalled();
    });
});

describe.sequential('bootstrap.js axios entry-point auth integration', () => {
    let axios;

    beforeEach(async () => {
        resetSessionLifecycleForTests();
        stubSessionLifecycleDom();
        window.location.replace = vi.fn();
        window.location.reload = vi.fn();
        vi.stubGlobal('fetch', vi.fn());
        vi.resetModules();
        await import('../../resources/js/bootstrap.js');
        axios = (await import('axios')).default;
    });

    afterEach(() => {
        window.sessionStorage.clear();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        vi.resetModules();
    });

    const rejectWithStatus = (status) => {
        axios.defaults.adapter = () => Promise.reject({
            response: {
                status,
                headers: { get: () => null },
            },
        });
    };

    it('axios 401 uses shared lifecycle redirect and logout broadcast', async () => {
        const postMessage = stubBroadcast();
        rejectWithStatus(401);

        await expect(axios.get('/api/sample')).rejects.toBeTruthy();

        expect(window.location.replace).toHaveBeenCalledWith('/login');
        expect(postMessage).toHaveBeenCalledWith(expect.objectContaining({ type: 'logout' }));
    });

    it('axios 403 rejects without login redirect or broadcast', async () => {
        const postMessage = stubBroadcast();
        rejectWithStatus(403);

        await expect(axios.get('/api/sample')).rejects.toBeTruthy();

        expect(window.location.replace).not.toHaveBeenCalled();
        expect(postMessage).not.toHaveBeenCalled();
    });

    it('axios 419 probes session and reloads when authenticated', async () => {
        stubBroadcast();
        rejectWithStatus(419);
        fetch.mockResolvedValueOnce(
            new Response(JSON.stringify({ authenticated: true }), { status: 200 }),
        );

        await expect(axios.get('/api/sample')).rejects.toBeTruthy();

        await vi.waitFor(() => {
            expect(fetch).toHaveBeenCalledWith('/session/status', expect.any(Object));
        });

        expect(window.location.reload).toHaveBeenCalledTimes(1);
        expect(window.location.replace).not.toHaveBeenCalled();
    });

    it('axios 419 redirects when probe returns 401', async () => {
        stubBroadcast();
        resetSessionLifecycleForTests();
        rejectWithStatus(419);
        fetch.mockImplementation((input) => {
            const url = typeof input === 'string' ? input : input?.url ?? '';

            if (String(url).includes('/session/status')) {
                return Promise.resolve(new Response(null, { status: 401 }));
            }

            return Promise.resolve(new Response(null, { status: 401 }));
        });

        await expect(axios.get('/api/sample')).rejects.toBeTruthy();

        await vi.waitFor(() => {
            expect(window.location.replace).toHaveBeenCalledWith('/login');
        });

        expect(window.location.reload).not.toHaveBeenCalled();
    });
});