import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applyAdminKpiSlotDom,
    applyKpiStripDom,
    kpiHtmlEquivalent,
    kpiStripMetricSignature,
} from '../../resources/js/dashboard-kpi-dom';
import { applyKpis } from '../../resources/js/live-dashboard';

const operatorStrip = ({ open = 3, overdue = 1, intake = 2 } = {}) => `
<div class="dashboard-kpi-strip" role="region" aria-label="Dashboard metrics">
    <a href="/dashboard?queue=action_required" data-workspace="action_required" class="dashboard-kpi-item">
        <div class="dashboard-kpi-content">
            <div class="dashboard-kpi-label">Open</div>
            <div class="dashboard-kpi-value">${open}</div>
        </div>
    </a>
    <a href="/dashboard?filter=overdue" data-workspace="overdue" class="dashboard-kpi-item">
        <div class="dashboard-kpi-content">
            <div class="dashboard-kpi-label">Overdue</div>
            <div class="dashboard-kpi-value">${overdue}</div>
        </div>
    </a>
    <a href="/admin/incoming-email" class="dashboard-kpi-item dashboard-email-intake-kpi" data-email-intake-kpi
       aria-label="Email Intake: ${intake} needs attention">
        <div class="dashboard-email-intake-kpi__value">${intake}</div>
        <div class="dashboard-email-intake-kpi__hover">
            <div class="dashboard-email-intake-kpi__hover-row">
                <span class="dashboard-email-intake-kpi__hover-label">Unknown</span>
                <span class="dashboard-email-intake-kpi__hover-count">${intake}</span>
            </div>
        </div>
    </a>
</div>
`;

describe('dashboard KPI DOM apply', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <div id="dashboard-kpi-strip" class="dashboard-kpi-strip-host">
                ${operatorStrip()}
            </div>
            <div class="dashboard-admin-metrics">
                <div data-admin-kpi-slot="online-users">
                    <div class="dashboard-kpi-item dashboard-kpi-item--online-users">
                        <div class="dashboard-kpi-label">Online Users</div>
                        <div class="dashboard-kpi-value">
                            <span class="dashboard-kpi-value-number">2</span>
                        </div>
                    </div>
                    <template class="dashboard-tooltip-template"><div>A, B</div></template>
                </div>
            </div>
        `;
    });

    it('skips replacing the KPI strip when metric values are unchanged', () => {
        const host = document.getElementById('dashboard-kpi-strip');
        const before = host.querySelector('.dashboard-kpi-value').textContent;

        expect(applyKpiStripDom('dashboard-kpi-strip', operatorStrip())).toBe('skipped');
        expect(host.querySelector('.dashboard-kpi-value').textContent).toBe(before);
    });

    it('patches KPI values in place when only numbers change', () => {
        const host = document.getElementById('dashboard-kpi-strip');
        const openNode = host.querySelector('[data-workspace="action_required"] .dashboard-kpi-value');

        expect(applyKpiStripDom('dashboard-kpi-strip', operatorStrip({ open: 9, overdue: 1, intake: 2 })))
            .toBe('patched');
        expect(openNode.textContent).toBe('9');
        expect(host.querySelector('[data-workspace="action_required"]')).toBe(openNode.closest('a'));
    });

    it('treats tooltip runtime attrs as non-changes', () => {
        const host = document.getElementById('dashboard-kpi-strip');
        const item = host.querySelector('.dashboard-kpi-item');
        item.setAttribute('aria-describedby', 'tooltip123');
        item.setAttribute('data-bs-original-title', 'x');

        const next = document.createElement('div');
        next.innerHTML = operatorStrip();
        const nextRoot = next.firstElementChild;

        expect(kpiHtmlEquivalent(host.querySelector('.dashboard-kpi-strip'), nextRoot)).toBe(true);
        expect(kpiStripMetricSignature(host.querySelector('.dashboard-kpi-strip')))
            .toBe(kpiStripMetricSignature(nextRoot));
    });

    it('skips admin online-users slot when unchanged', () => {
        const html = `
            <div class="dashboard-kpi-item dashboard-kpi-item--online-users">
                <div class="dashboard-kpi-label">Online Users</div>
                <div class="dashboard-kpi-value">
                    <span class="dashboard-kpi-value-number">2</span>
                </div>
            </div>
            <template class="dashboard-tooltip-template"><div>A, B</div></template>
        `;

        expect(applyAdminKpiSlotDom('[data-admin-kpi-slot="online-users"]', html)).toBe('skipped');
    });

    it('applyKpis skips tooltip re-init when the strip is unchanged', () => {
        const initSpy = vi.spyOn(HTMLElement.prototype, 'querySelector');

        applyKpis(operatorStrip());

        // Unchanged apply should not replace host children.
        expect(document.querySelector('[data-workspace="action_required"] .dashboard-kpi-value')?.textContent)
            .toBe('3');

        initSpy.mockRestore();
    });
});
