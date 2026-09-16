import { beforeEach, describe, expect, it } from 'vitest';
import { initHardwareDashboardSelection } from '../../resources/js/hardware-dashboard-selection';

describe('hardware dashboard selection', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <div data-hardware-workspace
                 data-hardware-bulk-labels-url="/inventory/hardware-fulfilments/bulk/labels"
                 data-hardware-bulk-manifest-url="/inventory/hardware-fulfilments/bulk/manifest">
                <div data-hardware-selection-bar class="d-none" hidden>
                    <span data-hardware-selection-count>0 selected</span>
                    <button type="button" data-hardware-bulk-labels disabled>Download Labels</button>
                    <button type="button" data-hardware-bulk-manifest disabled>Download Manifest</button>
                    <button type="button" data-hardware-open-selected disabled>Open selected</button>
                    <button type="button" data-hardware-clear-selected>Clear</button>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th><input type="checkbox" data-hardware-select-all aria-label="Select all"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr data-hardware-fulfilment-id="11">
                            <td><input type="checkbox" data-hardware-select value="11"></td>
                        </tr>
                        <tr data-hardware-fulfilment-id="12">
                            <td><input type="checkbox" data-hardware-select value="12"></td>
                        </tr>
                        <tr class="dashboard-case-row--filtered-out">
                            <td><input type="checkbox" data-hardware-select value="99"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        `;
        initHardwareDashboardSelection(document);
    });

    it('selects only visible rows with select all', () => {
        const selectAll = document.querySelector('[data-hardware-select-all]');
        selectAll.checked = true;
        selectAll.dispatchEvent(new Event('change', { bubbles: true }));

        const checked = [...document.querySelectorAll('[data-hardware-select]:checked')].map((node) => node.value);
        expect(checked).toEqual(['11', '12']);
        expect(document.querySelector('[data-hardware-selection-count]').textContent).toBe('2 selected');
        expect(document.querySelector('[data-hardware-bulk-labels]').disabled).toBe(false);
    });

    it('clears visible selection', () => {
        document.querySelectorAll('[data-hardware-select]').forEach((node) => {
            if (!node.closest('.dashboard-case-row--filtered-out')) {
                node.checked = true;
            }
        });
        document.querySelector('[data-hardware-select]').dispatchEvent(new Event('change', { bubbles: true }));

        document.querySelector('[data-hardware-clear-selected]').click();

        expect(document.querySelectorAll('[data-hardware-select]:checked')).toHaveLength(0);
        expect(document.querySelector('[data-hardware-selection-count]').textContent).toBe('0 selected');
        expect(document.querySelector('[data-hardware-bulk-manifest]').disabled).toBe(true);
    });
});
