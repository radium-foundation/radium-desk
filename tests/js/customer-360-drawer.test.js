import { afterEach, describe, expect, it, vi } from 'vitest';
import { initCustomer360Drawer } from '../../resources/js/customer-360-drawer';

describe('initCustomer360Drawer', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        document.body.classList.remove('customer-360-drawer-open');
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
                                <button type="button" data-workspace-trigger="remark">Remark</button>
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
});
