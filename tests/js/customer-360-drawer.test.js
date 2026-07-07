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
                    <div class="customer-360-drawer-body" data-customer-360-body>
                        <div data-customer-360-content-host></div>
                    </div>
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

    it('does not open drawer when clicking serial copy control', async () => {
        document.body.innerHTML = `
            <div id="dashboard-page" data-customer-360-url="http://localhost/dashboard/service-cases">
                <table>
                    <tbody>
                        <tr data-incident-id="42">
                            <td><a href="/incidents/42" class="case-reference-link">SC-001</a></td>
                            <td class="case-order-cell"><a href="/orders/99">RD-001</a></td>
                            <td class="serial-number-cell">
                                <button type="button"
                                        class="copyable-identifier"
                                        data-copyable-identifier="serial"
                                        data-copy-value="SN123456">SN123456</button>
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
                    <div data-customer-360-content-host"></div>
                </aside>
            </div>
        `;

        const pageRoot = document.getElementById('dashboard-page');

        global.fetch = vi.fn();

        initCustomer360Drawer({ pageRoot });

        document.querySelector('[data-copyable-identifier="serial"]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true, cancelable: true }),
        );

        expect(fetch).not.toHaveBeenCalled();
        expect(document.querySelector('[data-customer-360-drawer]')?.classList.contains('is-open')).toBe(false);
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
        contentHost.innerHTML = '<button type="button" class="customer-360-inline-copy" data-customer-360-copy="phone" data-copy-value="9876543210" data-copy-label="Customer Phone" aria-label="Copy Customer Phone"><i data-customer-360-copy-icon></i><span data-customer-360-copy-check hidden>✓</span></button>';

        const documentHandler = vi.fn();
        document.addEventListener('click', documentHandler);

        contentHost.querySelector('button')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        expect(documentHandler).not.toHaveBeenCalled();
    });

    it('copies inline field value and shows success feedback', async () => {
        const pageRoot = setupDashboard();
        const showToast = vi.fn();

        vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue(undefined);

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            text: async () => `
                <div data-customer-360-content>
                    <button type="button"
                            class="customer-360-inline-copy"
                            data-customer-360-copy="order-id"
                            data-copy-value="RD-360-HTML"
                            data-copy-label="Order ID"
                            aria-label="Copy Order ID">
                        <i data-customer-360-copy-icon></i>
                        <span data-customer-360-copy-check hidden>✓</span>
                    </button>
                </div>
            `,
        });

        initCustomer360Drawer({ pageRoot, showToast });

        document.querySelector('tr[data-incident-id="42"]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        await vi.waitFor(() => {
            expect(document.querySelector('[data-customer-360-copy]')).not.toBeNull();
        });

        document.querySelector('[data-customer-360-copy]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        await vi.waitFor(() => {
            expect(navigator.clipboard.writeText).toHaveBeenCalledWith('RD-360-HTML');
            expect(showToast).toHaveBeenCalledWith('Order ID copied');
        });

        const button = document.querySelector('[data-customer-360-copy]');
        expect(button?.classList.contains('is-copied')).toBe(true);
        expect(button?.querySelector('[data-customer-360-copy-icon]')?.hidden).toBe(true);
        expect(button?.querySelector('[data-customer-360-copy-check]')?.hidden).toBe(false);

        await vi.waitFor(async () => {
            await new Promise((resolve) => {
                setTimeout(resolve, 1600);
            });
            expect(button?.classList.contains('is-copied')).toBe(false);
        }, { timeout: 3000 });

        expect(button?.querySelector('[data-customer-360-copy-icon]')?.hidden).toBe(false);
        expect(button?.querySelector('[data-customer-360-copy-check]')?.hidden).toBe(true);
    });

    it('switches tabs using delegated events and preserves active tab across refresh', async () => {
        const pageRoot = setupDashboard();

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            text: async () => `
                <div class="customer-360-drawer-content" data-customer-360-content>
                    <button type="button" data-customer-360-tab="overview" class="nav-link active">Overview</button>
                    <button type="button" data-customer-360-tab="timeline" class="nav-link">Timeline</button>
                    <button type="button" data-customer-360-tab="ai-assistant" class="nav-link">IRA AI</button>
                    <div data-customer-360-tab-pane="overview" class="customer-360-tab-pane">Overview pane</div>
                    <div data-customer-360-tab-pane="timeline" class="customer-360-tab-pane d-none">Timeline pane</div>
                    <div data-customer-360-tab-pane="ai-assistant" class="customer-360-tab-pane d-none">AI pane</div>
                </div>
            `,
        });

        const drawer = initCustomer360Drawer({ pageRoot });

        await drawer.open('42', 'SC-001');

        document.querySelector('[data-customer-360-tab="ai-assistant"]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        expect(document.querySelector('[data-customer-360-content-host]')?.getAttribute('data-customer-360-active-tab')).toBe('ai-assistant');
        expect(document.querySelector('[data-customer-360-tab-pane="ai-assistant"]')?.classList.contains('d-none')).toBe(false);
        expect(document.querySelector('[data-customer-360-tab="ai-assistant"]')?.classList.contains('active')).toBe(true);
    });

    it('switches tabs repeatedly without losing active state', async () => {
        const pageRoot = setupDashboard();

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            text: async () => `
                <div class="customer-360-drawer-content" data-customer-360-content>
                    <button type="button" data-customer-360-tab="overview" class="nav-link active">Overview</button>
                    <button type="button" data-customer-360-tab="timeline" class="nav-link">Timeline</button>
                    <button type="button" data-customer-360-tab="ai-assistant" class="nav-link">IRA AI</button>
                    <div data-customer-360-tab-pane="overview" class="customer-360-tab-pane">Overview pane</div>
                    <div data-customer-360-tab-pane="timeline" class="customer-360-tab-pane d-none">Timeline pane</div>
                    <div data-customer-360-tab-pane="ai-assistant" class="customer-360-tab-pane d-none">AI pane</div>
                </div>
            `,
        });

        initCustomer360Drawer({ pageRoot });

        document.querySelector('tr[data-incident-id="42"]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        await vi.waitFor(() => {
            expect(document.querySelector('[data-customer-360-tab="overview"]')).not.toBeNull();
        });

        const clickTab = (tabKey) => {
            document.querySelector(`[data-customer-360-tab="${tabKey}"]`)?.dispatchEvent(
                new MouseEvent('click', { bubbles: true }),
            );
        };

        const expectActiveTab = (tabKey) => {
            expect(document.querySelector(`[data-customer-360-tab-pane="${tabKey}"]`)?.classList.contains('d-none')).toBe(false);
            expect(document.querySelector(`[data-customer-360-tab="${tabKey}"]`)?.classList.contains('active')).toBe(true);
        };

        clickTab('timeline');
        expectActiveTab('timeline');
        expect(document.querySelector('[data-customer-360-tab-pane="overview"]')?.classList.contains('d-none')).toBe(true);

        clickTab('overview');
        expectActiveTab('overview');

        clickTab('ai-assistant');
        expectActiveTab('ai-assistant');

        clickTab('timeline');
        expectActiveTab('timeline');

        clickTab('overview');
        expectActiveTab('overview');
    });

    const aiTabFixture = () => `
        <div class="customer-360-drawer-content" data-customer-360-content>
            <button type="button" data-customer-360-tab="overview" class="nav-link active">Overview</button>
            <button type="button" data-customer-360-tab="timeline" class="nav-link">Timeline</button>
            <button type="button" data-customer-360-tab="ai-assistant" class="nav-link">IRA AI</button>
            <div id="customer-360-tab-overview" data-customer-360-tab-pane="overview" class="customer-360-tab-pane">Overview pane</div>
            <div id="customer-360-tab-timeline" data-customer-360-tab-pane="timeline" class="customer-360-tab-pane d-none">Timeline pane</div>
            <div id="customer-360-tab-ai-assistant" data-customer-360-tab-pane="ai-assistant" class="customer-360-tab-pane d-none">
                <section data-customer-360-section="ai-advisor">IRA Advisor</section>
                <section class="customer-360-ai-assistant">Customer Intelligence</section>
                <section id="customer-360-ai-workbench" data-ai-workbench-root data-ai-workbench-refresh-url="/workbench">
                    <button type="button" data-ai-workbench-refresh data-artifact-key="workbench">Refresh</button>
                    <h3>Suggested Next Workflow</h3>
                </section>
            </div>
        </div>
    `;

    it('keeps IRA AI workbench and workflow content inside the ai-assistant tab pane', async () => {
        const pageRoot = setupDashboard();

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            text: async () => aiTabFixture(),
        });

        const drawer = initCustomer360Drawer({ pageRoot });

        await drawer.open('42', 'SC-001');

        const aiTab = document.querySelector('#customer-360-tab-ai-assistant');
        const workbench = document.querySelector('#customer-360-ai-workbench');

        expect(aiTab).not.toBeNull();
        expect(workbench).not.toBeNull();
        expect(aiTab?.contains(workbench)).toBe(true);
        expect(aiTab?.textContent).toContain('Suggested Next Workflow');
        expect(document.querySelector('[data-customer-360-content]')?.contains(workbench)).toBe(true);
        expect(document.querySelector('[data-customer-360-content]')?.querySelectorAll('#customer-360-ai-workbench')).toHaveLength(1);
    });

    it('does not leave orphan IRA AI nodes outside the ai-assistant tab pane', async () => {
        const pageRoot = setupDashboard();

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            text: async () => aiTabFixture(),
        });

        const drawer = initCustomer360Drawer({ pageRoot });

        await drawer.open('42', 'SC-001');

        const contentRoot = document.querySelector('[data-customer-360-content]');
        const aiTab = document.querySelector('#customer-360-tab-ai-assistant');

        contentRoot?.querySelectorAll('[data-ai-workbench-root], #customer-360-ai-workbench').forEach((node) => {
            expect(aiTab?.contains(node)).toBe(true);
        });
    });

    it('resets drawer body scroll to top after tab switch', async () => {
        const pageRoot = setupDashboard();

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            text: async () => `
                <div class="customer-360-drawer-content" data-customer-360-content>
                    <button type="button" data-customer-360-tab="overview" class="nav-link active">Overview</button>
                    <button type="button" data-customer-360-tab="timeline" class="nav-link">Timeline</button>
                    <button type="button" data-customer-360-tab="ai-assistant" class="nav-link">IRA AI</button>
                    <div data-customer-360-tab-pane="overview" class="customer-360-tab-pane">Overview pane</div>
                    <div data-customer-360-tab-pane="timeline" class="customer-360-tab-pane d-none">Timeline pane</div>
                    <div data-customer-360-tab-pane="ai-assistant" class="customer-360-tab-pane d-none">AI pane</div>
                </div>
            `,
        });

        const drawer = initCustomer360Drawer({ pageRoot });

        await drawer.open('42', 'SC-001');

        const drawerBody = document.querySelector('[data-customer-360-body]');

        Object.defineProperty(drawerBody, 'scrollTop', {
            writable: true,
            configurable: true,
            value: 480,
        });

        document.querySelector('[data-customer-360-tab="timeline"]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        expect(drawerBody?.scrollTop).toBe(0);

        Object.defineProperty(drawerBody, 'scrollTop', {
            writable: true,
            configurable: true,
            value: 320,
        });

        document.querySelector('[data-customer-360-tab="ai-assistant"]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        expect(drawerBody?.scrollTop).toBe(0);
    });

    it('logs IRA AI DOM integrity errors in development when structure is invalid', async () => {
        const pageRoot = setupDashboard();
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            text: async () => `
                <div class="customer-360-drawer-content" data-customer-360-content>
                    <div id="customer-360-tab-ai-assistant" data-customer-360-tab-pane="ai-assistant"></div>
                    <section id="customer-360-ai-workbench" data-ai-workbench-root>Orphan workbench</section>
                </div>
            `,
        });

        const drawer = initCustomer360Drawer({ pageRoot });

        await drawer.open('42', 'SC-001');

        expect(consoleError).toHaveBeenCalledWith('Customer360: IRA AI DOM structure invalid');

        consoleError.mockRestore();
    });

    it('preserves active tab after drawer refresh, workbench refresh, and customer360:refresh', async () => {
        const pageRoot = setupDashboard();
        const showToast = vi.fn();

        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                text: async () => aiTabFixture(),
            })
            .mockResolvedValueOnce({
                ok: true,
                text: async () => aiTabFixture(),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    html: `
                        <section id="customer-360-ai-workbench" data-ai-workbench-root>
                            <h3>Suggested Next Workflow</h3>
                        </section>
                    `,
                }),
            });

        const drawer = initCustomer360Drawer({ pageRoot, showToast });

        await drawer.open('42', 'SC-001');

        document.querySelector('[data-customer-360-tab="ai-assistant"]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        expect(document.querySelector('[data-customer-360-content-host]')?.getAttribute('data-customer-360-active-tab')).toBe('ai-assistant');

        document.dispatchEvent(new CustomEvent('customer360:refresh', {
            detail: { incidentId: '42' },
        }));

        await vi.waitFor(() => {
            expect(fetch).toHaveBeenCalledTimes(2);
        });

        expect(document.querySelector('[data-customer-360-content-host]')?.getAttribute('data-customer-360-active-tab')).toBe('ai-assistant');
        expect(document.querySelector('[data-customer-360-tab-pane="ai-assistant"]')?.classList.contains('d-none')).toBe(false);

        document.querySelector('[data-ai-workbench-refresh]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        await vi.waitFor(() => {
            expect(fetch).toHaveBeenCalledTimes(3);
        });

        expect(document.querySelector('[data-customer-360-content-host]')?.getAttribute('data-customer-360-active-tab')).toBe('ai-assistant');
        expect(document.querySelector('#customer-360-tab-ai-assistant')?.contains(document.querySelector('#customer-360-ai-workbench'))).toBe(true);
    });

    it('shows global error banner only when initial drawer content request fails', async () => {
        const pageRoot = setupDashboard();

        global.fetch = vi.fn().mockResolvedValue({
            ok: false,
            status: 503,
            text: async () => '',
        });

        const drawer = initCustomer360Drawer({ pageRoot });

        await drawer.open('42', 'SC-001');

        const errorState = document.querySelector('[data-customer-360-error]');
        expect(errorState?.classList.contains('d-none')).toBe(false);
        expect(errorState?.textContent).toBe('Unable to load customer details. Please try again.');
        expect(document.querySelector('[data-customer-360-content-host]')?.innerHTML).toBe('');
    });

    it('does not show global error banner when drawer refresh fails after successful load', async () => {
        const pageRoot = setupDashboard();
        const showToast = vi.fn();

        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                text: async () => '<div data-customer-360-content>Loaded overview</div>',
            })
            .mockResolvedValueOnce({
                ok: false,
                status: 500,
                text: async () => '',
            });

        const drawer = initCustomer360Drawer({ pageRoot, showToast });

        await drawer.open('42', 'SC-001');

        document.dispatchEvent(new CustomEvent('customer360:refresh', {
            detail: { incidentId: '42' },
        }));

        await vi.waitFor(() => {
            expect(fetch).toHaveBeenCalledTimes(2);
        });

        const errorState = document.querySelector('[data-customer-360-error]');
        expect(errorState?.classList.contains('d-none')).toBe(true);
        expect(document.querySelector('[data-customer-360-content-host]')?.innerHTML).toContain('Loaded overview');
        expect(showToast).toHaveBeenCalledWith('Unable to refresh customer details. Please try again.');
    });

    it('does not show global error banner when post-render initialization throws', async () => {
        const pageRoot = setupDashboard();
        const initTooltips = vi.fn(() => {
            throw new Error('Tooltip init failed');
        });

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            text: async () => '<div data-customer-360-content>Loaded overview</div>',
        });

        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});

        const drawer = initCustomer360Drawer({ pageRoot, initTooltips });

        await drawer.open('42', 'SC-001');

        const errorState = document.querySelector('[data-customer-360-error]');
        expect(errorState?.classList.contains('d-none')).toBe(true);
        expect(document.querySelector('[data-customer-360-content-host]')?.innerHTML).toContain('Loaded overview');

        consoleError.mockRestore();
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

    it('disables and refreshes drawer when radiumbox sync succeeds', async () => {
        const pageRoot = setupDashboard();
        const showToast = vi.fn();

        global.fetch = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                text: async () => `
                    <div data-customer-360-content>
                        <div data-customer-360-device-section>
                            <button type="button"
                                    data-customer-360-radiumbox-sync
                                    data-sync-url="http://localhost/dashboard/service-cases/42/customer-360/radiumbox-sync">
                                <svg class="heroicon heroicon-arrow-path"></svg>
                            </button>
                        </div>
                    </div>
                `,
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    success: true,
                    message: '✓ Device information synchronized successfully.',
                    device_html: '<div data-customer-360-device-section>Synced</div>',
                }),
            });

        initCustomer360Drawer({ pageRoot, showToast });

        document.querySelector('tr[data-incident-id="42"]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        await vi.waitFor(() => {
            expect(document.querySelector('[data-customer-360-radiumbox-sync]')).not.toBeNull();
        });

        document.querySelector('[data-customer-360-radiumbox-sync]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        await vi.waitFor(() => {
            expect(fetch).toHaveBeenCalledTimes(2);
        });

        expect(fetch).toHaveBeenNthCalledWith(
            2,
            'http://localhost/dashboard/service-cases/42/customer-360/radiumbox-sync',
            expect.objectContaining({ method: 'POST' }),
        );

        await vi.waitFor(() => {
            expect(document.querySelector('[data-customer-360-device-section]')?.innerHTML).toContain('Synced');
        });

        expect(showToast).toHaveBeenCalledWith('✓ Device information synchronized successfully.');
    });
});
