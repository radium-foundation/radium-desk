import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applyHardwareFilterCounts,
    applyHardwareLivePayload,
    handleHardwareFulfilmentsUpdated,
} from '../../resources/js/hardware-dashboard-live';

describe('hardware dashboard live', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <div id="dashboard-page" data-live-hardware-url="/dashboard/live/hardware"></div>
            <nav class="dashboard-hardware-nav">
                <span data-hardware-scope-count="active">(2)</span>
                <span data-hardware-scope-count="shipped">(0)</span>
                <span data-hardware-filter-count="ready">(2)</span>
                <span data-hardware-filter-count="pickup">(1)</span>
                <span data-dashboard-case-filter-count="hardware">(3)</span>
            </nav>
            <div id="dashboard-hardware-workspace"
                 data-hardware-scope="active"
                 data-hardware-filter="ready">
                <table>
                    <tbody id="dashboard-hardware-body">
                        <tr data-hardware-fulfilment-id="11" data-hardware-queue="ready">
                            <td class="dashboard-hardware-status">Awaiting Serial</td>
                            <td>Allocate Serial</td>
                        </tr>
                        <tr data-hardware-fulfilment-id="12" data-hardware-queue="ready">
                            <td>Stay</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        `;
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('patches the affected row, scope/filter counts, and view membership without reloading', () => {
        const reload = vi.fn();
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { ...window.location, reload },
        });

        applyHardwareLivePayload({
            scope_counts: { active: 2, shipped: 1 },
            filter_counts: { all: 2, ready: 1, exceptions: 0, pickup: 2, scheduled: 0 },
            hardware_count: 2,
            remove_fulfilment_ids: [],
            rows: [{
                fulfilment_id: 11,
                scope: 'active',
                filter: 'ready',
                queue: 'ready',
                html: '<tr data-hardware-fulfilment-id="11" data-hardware-queue="ready"><td class="dashboard-hardware-status">Ready for Shipment</td><td>Create Shipment</td></tr>',
            }],
        });

        expect(document.querySelector('[data-hardware-fulfilment-id="11"]')?.dataset.hardwareQueue).toBe('ready');
        expect(document.querySelector('[data-hardware-fulfilment-id="11"] .dashboard-hardware-status')?.textContent).toBe('Ready for Shipment');
        expect(document.querySelector('[data-hardware-fulfilment-id="12"]')?.textContent).toContain('Stay');
        expect(document.querySelector('[data-hardware-scope-count="active"]')?.textContent).toBe('(2)');
        expect(document.querySelector('[data-hardware-filter-count="ready"]')?.textContent).toBe('(1)');
        expect(document.querySelector('[data-hardware-filter-count="pickup"]')?.textContent).toBe('(2)');
        expect(document.querySelector('[data-dashboard-case-filter-count="hardware"]')?.textContent).toBe('(2)');
        expect(reload).not.toHaveBeenCalled();
    });

    it('removes an existing row that no longer matches the active filter', () => {
        applyHardwareLivePayload({
            scope_counts: { active: 1 },
            filter_counts: { ready: 1, pickup: 1 },
            hardware_count: 1,
            remove_fulfilment_ids: [],
            rows: [{
                fulfilment_id: 11,
                scope: 'active',
                filter: 'pickup',
                queue: 'pickup',
                html: '<tr data-hardware-fulfilment-id="11" data-hardware-queue="pickup"><td>Pickup</td></tr>',
            }],
        });

        expect(document.querySelector('[data-hardware-fulfilment-id="11"]')).toBeNull();
        expect(document.querySelector('[data-hardware-fulfilment-id="12"]')).not.toBeNull();
    });

    it('removes a row that left the active hardware filter', () => {
        applyHardwareLivePayload({
            scope_counts: { active: 1 },
            filter_counts: { ready: 1 },
            hardware_count: 1,
            remove_fulfilment_ids: [11],
            rows: [],
        });

        expect(document.querySelector('[data-hardware-fulfilment-id="11"]')).toBeNull();
        expect(document.querySelector('[data-hardware-fulfilment-id="12"]')).not.toBeNull();
    });

    it('refetches live rows with hw_scope and hw_filter without a full page reload', async () => {
        const reload = vi.fn();
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { ...window.location, reload },
        });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                scope_counts: { active: 1 },
                filter_counts: { ready: 0, pickup: 1 },
                hardware_count: 1,
                remove_fulfilment_ids: [11],
                rows: [],
            }),
        }));

        await handleHardwareFulfilmentsUpdated(
            document.getElementById('dashboard-page'),
            { fulfilment_ids: [11] },
        );

        expect(fetch).toHaveBeenCalledWith(
            '/dashboard/live/hardware?ids%5B%5D=11&hw_scope=active&hw_filter=ready',
            expect.objectContaining({ credentials: 'same-origin' }),
        );
        expect(document.querySelector('[data-hardware-fulfilment-id="11"]')).toBeNull();
        expect(reload).not.toHaveBeenCalled();
    });

    it('updates scope and filter chip counts independently', () => {
        applyHardwareFilterCounts({ active: 4, shipped: 2 }, { ready: 3, pickup: 1 }, 9);
        expect(document.querySelector('[data-hardware-scope-count="active"]')?.textContent).toBe('(4)');
        expect(document.querySelector('[data-hardware-scope-count="shipped"]')?.textContent).toBe('(2)');
        expect(document.querySelector('[data-hardware-filter-count="ready"]')?.textContent).toBe('(3)');
        expect(document.querySelector('[data-hardware-filter-count="pickup"]')?.textContent).toBe('(1)');
        expect(document.querySelector('[data-dashboard-case-filter-count="hardware"]')?.textContent).toBe('(9)');
    });
});
