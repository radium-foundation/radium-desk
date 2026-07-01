import { afterEach, describe, expect, it, vi } from 'vitest';
import { initCustomer360Drawer } from '../../resources/js/customer-360-drawer';
import { initWorkspace } from '../../resources/js/workspace';
import { resetWorkspaceSession } from '../../resources/js/workspace/session';

describe('initCustomer360Drawer', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        document.body.classList.remove('customer-360-drawer-open');
        resetWorkspaceSession();
        vi.restoreAllMocks();
    });

    const setupDashboard = () => {
        document.body.innerHTML = `
            <div id="dashboard-page" data-customer-360-url="http://localhost/dashboard/service-cases">
                <table>
                    <tbody>
                        <tr data-incident-id="42">
                            <td><a href="/incidents/42" class="case-reference-link">SC-001</a></td>
                            <td class="case-order-cell"><a href="/orders/99">RD-001</a></td>
                            <td class="dashboard-actions-cell">
                                <button type="button" data-workspace-trigger="remark">Note</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div data-customer-360-drawer aria-hidden="true">
                <div data-customer-360-backdrop></div>
                <aside data-customer-360-panel>
                    <button type="button" data-customer-360-close></button>
                    <span data-customer-360-subtitle></span>
                    <div data-customer-360-loading hidden></div>
                    <div data-customer-360-error class="d-none"></div>
                    <div data-customer-360-content-host></div>
                </aside>
            </div>
        `;

        return document.getElementById('dashboard-page');
    };

    it('opens drawer and loads content when clicking a service case row', async () => {
        const pageRoot = setupDashboard();

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            text: async () => '<div data-customer-360-content>Loaded</div>',
        });

        const drawer = initCustomer360Drawer({ pageRoot });
        const row = document.querySelector('tr[data-incident-id="42"]');

        row?.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        await vi.waitFor(() => {
            expect(document.querySelector('[data-customer-360-drawer]')?.classList.contains('is-open')).toBe(true);
        });

        expect(fetch).toHaveBeenCalledWith(
            'http://localhost/dashboard/service-cases/42/customer-360',
            expect.objectContaining({ method: 'GET' }),
        );

        await vi.waitFor(() => {
            expect(document.querySelector('[data-customer-360-content-host]')?.innerHTML).toContain('Loaded');
        });

        expect(document.body.classList.contains('customer-360-drawer-open')).toBe(true);
        expect(document.querySelector('[data-customer-360-subtitle]')?.textContent).toBe('SC-001');

        drawer?.close();

        expect(document.querySelector('[data-customer-360-drawer]')?.classList.contains('is-open')).toBe(false);
    });

    it('does not open drawer when clicking action buttons', async () => {
        const pageRoot = setupDashboard();

        global.fetch = vi.fn();

        initCustomer360Drawer({ pageRoot });

        document.querySelector('[data-workspace-trigger="remark"]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        expect(fetch).not.toHaveBeenCalled();
        expect(document.querySelector('[data-customer-360-drawer]')?.classList.contains('is-open')).toBe(false);
    });

    it('opens drawer when clicking row navigation links', async () => {
        const pageRoot = setupDashboard();

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            text: async () => '<div data-customer-360-content>Loaded</div>',
        });

        initCustomer360Drawer({ pageRoot });

        document.querySelector('.case-reference-link')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true, cancelable: true }),
        );

        await vi.waitFor(() => {
            expect(document.querySelector('[data-customer-360-drawer]')?.classList.contains('is-open')).toBe(true);
        });

        expect(fetch).toHaveBeenCalledWith(
            'http://localhost/dashboard/service-cases/42/customer-360',
            expect.objectContaining({ method: 'GET' }),
        );

        document.querySelector('.case-order-cell a')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true, cancelable: true }),
        );

        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('closes drawer on escape key', async () => {
        const pageRoot = setupDashboard();

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            text: async () => '<div>Loaded</div>',
        });

        const drawer = initCustomer360Drawer({ pageRoot });

        document.querySelector('tr[data-incident-id="42"]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        await vi.waitFor(() => {
            expect(drawer?.isOpen()).toBe(true);
        });

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(drawer?.isOpen()).toBe(false);
    });

    it('allows workspace trigger clicks inside the drawer panel to bubble', () => {
        const pageRoot = setupDashboard();

        initCustomer360Drawer({ pageRoot });

        const contentHost = document.querySelector('[data-customer-360-content-host]');
        contentHost.innerHTML = '<button type="button" data-workspace-trigger="request-serial">Request Serial</button>';

        const documentHandler = vi.fn();
        document.addEventListener('click', documentHandler);

        document.querySelector('[data-workspace-trigger="request-serial"]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        expect(documentHandler).toHaveBeenCalled();
    });

    it('keeps ordinary drawer panel clicks from bubbling to document', () => {
        const pageRoot = setupDashboard();

        initCustomer360Drawer({ pageRoot });

        const contentHost = document.querySelector('[data-customer-360-content-host]');
        contentHost.innerHTML = '<button type="button" data-customer-360-copy="phone" data-copy-value="9876543210">Copy Phone</button>';

        const documentHandler = vi.fn();
        document.addEventListener('click', documentHandler);

        contentHost.querySelector('button')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        expect(documentHandler).not.toHaveBeenCalled();
    });

    it('opens workspace component when clicking request-serial inside drawer content', async () => {
        document.body.innerHTML = `
            <script type="application/json" id="workspace-context-slugs">${JSON.stringify({
                Dashboard: 'dashboard',
                ServiceCase: 'service_case',
                Order: 'order',
                Customer: 'customer',
                Mobile: 'mobile',
                Api: 'api',
                Ai: 'ai',
            })}</script>
            <div id="dashboard-page" data-customer-360-url="http://localhost/dashboard/service-cases"></div>
            <div data-customer-360-drawer aria-hidden="true">
                <div data-customer-360-backdrop></div>
                <aside data-customer-360-panel>
                    <button type="button" data-customer-360-close></button>
                    <span data-customer-360-subtitle></span>
                    <div data-customer-360-loading hidden></div>
                    <div data-customer-360-error class="d-none"></div>
                    <div data-customer-360-content-host">
                        <button type="button"
                                data-workspace-trigger="request-serial"
                                data-workspace-incident-id="42"
                                data-workspace-context="customer">
                            Request Serial
                        </button>
                    </div>
                </aside>
            </div>
            <div data-workspace-modal-host>
                <div data-workspace-modal-content></div>
            </div>
        `;

        window.bootstrap = {
            Modal: {
                getOrCreateInstance: vi.fn(() => ({
                    show: vi.fn(),
                })),
            },
        };

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            text: async () => '<form data-workspace-action-form="request-serial"></form>',
        });

        resetWorkspaceSession();
        initWorkspace();
        initCustomer360Drawer({ pageRoot: document.getElementById('dashboard-page') });

        document.querySelector('[data-workspace-trigger="request-serial"]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true, cancelable: true }),
        );

        await vi.waitFor(() => {
            expect(fetch).toHaveBeenCalledWith(
                '/incidents/42/components/request-serial?context=customer',
                expect.objectContaining({
                    headers: expect.objectContaining({
                        Accept: 'text/html',
                        'X-Requested-With': 'XMLHttpRequest',
                    }),
                }),
            );
        });
    });
});
