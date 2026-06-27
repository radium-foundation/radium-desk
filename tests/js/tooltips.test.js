import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import * as bootstrap from 'bootstrap';
import { initTooltips } from '../../resources/js/tooltips';

describe('initTooltips', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    afterEach(() => {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
            bootstrap.Tooltip.getInstance(element)?.dispose();
        });
    });

    it('initializes plain text tooltips', () => {
        document.body.innerHTML = `
            <span data-bs-toggle="tooltip" data-bs-title="Plain text">Avatar</span>
        `;

        initTooltips();

        expect(bootstrap.Tooltip.getInstance(document.querySelector('span'))).toBeTruthy();
    });

    it('initializes premium html tooltips with custom class', () => {
        document.body.innerHTML = `
            <span
                data-bs-toggle="tooltip"
                data-bs-html="true"
                data-bs-custom-class="dashboard-premium-tooltip-wrapper"
                data-bs-title="&lt;div class=&quot;dashboard-premium-tooltip&quot;&gt;Line&lt;/div&gt;"
            >SLA</span>
        `;

        initTooltips();

        const instance = bootstrap.Tooltip.getInstance(document.querySelector('span'));

        expect(instance).toBeTruthy();
        expect(instance._config.html).toBe(true);
        expect(instance._config.customClass).toBe('dashboard-premium-tooltip-wrapper');
    });

    it('initializes body container tooltips used by online users kpi', () => {
        document.body.innerHTML = `
            <div
                data-bs-toggle="tooltip"
                data-bs-html="true"
                data-bs-container="body"
                data-bs-boundary="viewport"
                data-bs-custom-class="dashboard-premium-tooltip-wrapper"
                data-bs-title="&lt;div&gt;Online&lt;/div&gt;"
            >Online Users</div>
        `;

        expect(() => initTooltips()).not.toThrow();

        const instance = bootstrap.Tooltip.getInstance(document.querySelector('div'));

        expect(instance).toBeTruthy();
        expect(instance._config.container).toBe(document.body);
    });

    it('only reinitializes tooltips within the provided root', () => {
        document.body.innerHTML = `
            <div id="kpi-root">
                <span data-bs-toggle="tooltip" data-bs-title="KPI">KPI</span>
            </div>
            <table>
                <tbody id="tbody">
                    <tr>
                        <td><span data-bs-toggle="tooltip" data-bs-title="Row">Row</span></td>
                    </tr>
                </tbody>
            </table>
        `;

        initTooltips();

        const kpiInstance = bootstrap.Tooltip.getInstance(document.querySelector('#kpi-root span'));
        const rowInstance = bootstrap.Tooltip.getInstance(document.querySelector('#tbody span'));

        initTooltips(document.getElementById('kpi-root'));

        expect(bootstrap.Tooltip.getInstance(document.querySelector('#kpi-root span'))).toBeTruthy();
        expect(bootstrap.Tooltip.getInstance(document.querySelector('#kpi-root span'))).not.toBe(kpiInstance);
        expect(bootstrap.Tooltip.getInstance(document.querySelector('#tbody span'))).toBe(rowInstance);
    });
});
