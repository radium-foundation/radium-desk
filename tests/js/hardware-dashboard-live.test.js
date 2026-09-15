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
            <nav class="dashboard-operation-queues">
                <a href="/dashboard?workspace=hardware&hw_queue=ready">
                    <span class="dashboard-case-filter-chip__count">(2)</span>
                </a>
                <a href="/dashboard?workspace=hardware&hw_queue=pickup">
                    <span class="dashboard-case-filter-chip__count">(1)</span>
                </a>
                <span data-dashboard-case-filter-count="hardware">(3)</span>
            </nav>
            <div id="dashboard-hardware-workspace" data-hardware-queue="ready">
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

    it('patches the affected row, counts, and queue membership without reloading', () => {
        const reload = vi.fn();
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { ...window.location, reload },
        });

        applyHardwareLivePayload({
            counts: { ready: 1, pickup: 2, exceptions: 0, completed: 0 },
            hardware_count: 3,
            remove_fulfilment_ids: [],
            rows: [{
                fulfilment_id: 11,
                queue: 'pickup',
                html: '<tr data-hardware-fulfilment-id="11" data-hardware-queue="pickup"><td class="dashboard-hardware-status">In Transit</td><td>View</td></tr>',
            }],
        });

        expect(document.querySelector('[data-hardware-fulfilment-id="11"]')?.dataset.hardwareQueue).toBe('pickup');
        expect(document.querySelector('[data-hardware-fulfilment-id="11"] .dashboard-hardware-status')?.textContent).toBe('In Transit');
        expect(document.querySelector('[data-hardware-fulfilment-id="12"]')?.textContent).toContain('Stay');
        expect(document.querySelector('[href*="hw_queue=ready"] .dashboard-case-filter-chip__count')?.textContent).toBe('(1)');
        expect(document.querySelector('[href*="hw_queue=pickup"] .dashboard-case-filter-chip__count')?.textContent).toBe('(2)');
        expect(document.querySelector('[data-dashboard-case-filter-count="hardware"]')?.textContent).toBe('(3)');
        expect(reload).not.toHaveBeenCalled();
    });

    it('removes a row that left the active hardware queue', () => {
        applyHardwareLivePayload({
            counts: { ready: 1 },
            hardware_count: 1,
            remove_fulfilment_ids: [11],
            rows: [],
        });

        expect(document.querySelector('[data-hardware-fulfilment-id="11"]')).toBeNull();
        expect(document.querySelector('[data-hardware-fulfilment-id="12"]')).not.toBeNull();
    });

    it('refetches live rows from HardwareFulfilmentsUpdated without a full page reload', async () => {
        const reload = vi.fn();
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { ...window.location, reload },
        });
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                counts: { ready: 0, pickup: 1 },
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
            '/dashboard/live/hardware?ids%5B%5D=11&hw_queue=ready',
            expect.objectContaining({ credentials: 'same-origin' }),
        );
        expect(document.querySelector('[data-hardware-fulfilment-id="11"]')).toBeNull();
        expect(reload).not.toHaveBeenCalled();
    });

    it('updates hardware chip counts independently', () => {
        applyHardwareFilterCounts({ ready: 4 }, 9);
        expect(document.querySelector('[href*="hw_queue=ready"] .dashboard-case-filter-chip__count')?.textContent).toBe('(4)');
        expect(document.querySelector('[data-dashboard-case-filter-count="hardware"]')?.textContent).toBe('(9)');
    });
});
