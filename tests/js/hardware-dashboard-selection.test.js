import { describe, expect, it, beforeEach } from 'vitest';
import { initHardwareDashboardSelection } from '../../resources/js/hardware-dashboard-selection';

describe('hardware dashboard selection', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <div data-hardware-workspace
                 data-hardware-download-labels-url="/bulk/download/labels">
                <div data-hardware-selection-bar>
                    <span data-hardware-selection-count></span>
                    <span data-hardware-selection-eligible"></span>
                    <button type="button" data-hardware-download-labels disabled></button>
                </div>
                <table>
                    <tr data-hardware-fulfilment-id="10">
                        <td><input type="checkbox" data-hardware-select data-hardware-fulfilment-id="10" value="10"></td>
                    </tr>
                    <tr data-hardware-fulfilment-id="11">
                        <td><input type="checkbox" data-hardware-select data-hardware-fulfilment-id="11" value="11"></td>
                    </tr>
                </table>
            </div>
        `;
        initHardwareDashboardSelection(document);
    });

    it('enables download when fulfilment rows are selected', () => {
        const workspace = document.querySelector('[data-hardware-workspace]');
        const first = workspace.querySelector('[data-hardware-select]');
        first.checked = true;
        first.dispatchEvent(new Event('change', { bubbles: true }));

        expect(workspace.querySelector('[data-hardware-download-labels]').disabled).toBe(false);
        expect(workspace.querySelector('[data-hardware-selection-eligible]').textContent)
            .toContain('eligible for download');
    });
});