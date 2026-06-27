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

        const instance = bootstrap.Tooltip.getInstance(document.querySelector('span'));

        expect(instance).toBeTruthy();
        expect(instance._config.title).toBe('Plain text');
        expect(instance._config.html).toBeFalsy();
    });

    it('initializes premium tooltips from adjacent template content', () => {
        document.body.innerHTML = `
            <span
                data-bs-toggle="tooltip"
                data-dashboard-tooltip
                data-bs-custom-class="dashboard-premium-tooltip-wrapper"
            >SLA</span>
            <template class="dashboard-tooltip-template">
                <div class="dashboard-premium-tooltip dashboard-premium-tooltip--compact">
                    <div class="dashboard-premium-tooltip__compact-line">26 Jun 2026, 06:46 PM</div>
                    <div class="dashboard-premium-tooltip__compact-line">
                        <span class="dashboard-sla-tooltip-duration--overdue">15h 29m</span>
                    </div>
                </div>
            </template>
        `;

        initTooltips();

        const instance = bootstrap.Tooltip.getInstance(document.querySelector('span'));

        expect(instance).toBeTruthy();
        expect(instance._config.html).toBe(true);
        expect(instance._config.customClass).toBe('dashboard-premium-tooltip-wrapper');
        expect(instance._config.title).toContain('dashboard-premium-tooltip--compact');
        expect(instance._config.title).toContain('15h 29m');
        expect(instance._config.title).not.toContain('&lt;');
    });

    it('initializes body container tooltips used by online users kpi', () => {
        document.body.innerHTML = `
            <div
                data-bs-toggle="tooltip"
                data-dashboard-tooltip
                data-bs-container="body"
                data-bs-boundary="viewport"
                data-bs-custom-class="dashboard-premium-tooltip-wrapper"
            >Online Users</div>
            <template class="dashboard-tooltip-template">
                <div class="dashboard-premium-tooltip">
                    <div class="dashboard-premium-tooltip__title">Currently Online</div>
                </div>
            </template>
        `;

        expect(() => initTooltips()).not.toThrow();

        const instance = bootstrap.Tooltip.getInstance(document.querySelector('div'));

        expect(instance).toBeTruthy();
        expect(instance._config.container).toBe(document.body);
        expect(instance._config.html).toBe(true);
        expect(instance._config.title).toContain('Currently Online');
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
