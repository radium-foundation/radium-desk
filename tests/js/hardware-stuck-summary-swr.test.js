import { describe, expect, it, vi } from 'vitest';
import {
    applyHardwareStuckOrdersRealtimePayload,
    applyHardwareStuckSummaryHtml,
    initHardwareStuckSummaryAsync,
} from '../../resources/js/hardware-stuck-summary-async';

describe('hardware stuck summary SWR', () => {
    it('ignores older realtime versions', () => {
        document.body.innerHTML = `
            <div id="host"
                 data-hardware-stuck-loaded="1"
                 data-hardware-stuck-version="5"
                 data-hardware-stuck-url="/dashboard/hardware/stuck-summary">
                <span class="dashboard-kpi-value">4</span>
            </div>
        `;

        const host = document.getElementById('host');
        const html = `
            <div data-hardware-stuck-loaded="1" data-hardware-stuck-version="3">
                <span class="dashboard-kpi-value">9</span>
            </div>
        `;

        expect(applyHardwareStuckSummaryHtml(host, html, { version: 3 })).toBe(false);
        expect(document.querySelector('.dashboard-kpi-value')?.textContent).toBe('4');
    });

    it('applies newer realtime payloads', () => {
        document.body.innerHTML = `
            <div data-hardware-stuck-loaded="1"
                 data-hardware-stuck-version="2"
                 data-hardware-stuck-url="/dashboard/hardware/stuck-summary">
                <span class="dashboard-kpi-value">1</span>
            </div>
        `;

        applyHardwareStuckOrdersRealtimePayload(document, {
            version: 4,
            html: `<div data-hardware-stuck-loaded="1" data-hardware-stuck-version="4"><span class="dashboard-kpi-value">7</span></div>`,
        });

        expect(document.querySelector('.dashboard-kpi-value')?.textContent).toBe('7');
    });

    it('does not surface fetch errors when background refresh fails with cached counts', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 503 }));

        document.body.innerHTML = `
            <div data-hardware-stuck-loaded="1"
                 data-hardware-stuck-url="/dashboard/hardware/stuck-summary"
                 data-hardware-stuck-refresh-ms="30000">
                <span data-hardware-stuck-status>ok</span>
            </div>
        `;

        const { initHardwareStuckSummaryAsync } = await import('../../resources/js/hardware-stuck-summary-async');
        initHardwareStuckSummaryAsync(document);

        await new Promise((resolve) => {
            setTimeout(resolve, 0);
        });

        const status = document.querySelector('[data-hardware-stuck-status]');
        expect(status?.textContent).toBe('ok');
    });

    it('re-binds breakdown controls after async html swap', () => {
        document.body.innerHTML = `
            <div id="host" data-hardware-stuck-loaded="1" data-hardware-stuck-breakdown>
                <span class="dashboard-kpi-value">1</span>
            </div>
        `;

        const host = document.getElementById('host');
        const html = `
            <div class="admin-hardware-stuck-kpi" data-hardware-stuck-loaded="1" data-hardware-stuck-breakdown>
                <span class="dashboard-kpi-value">3</span>
                <button type="button" data-hardware-stuck-breakdown-trigger aria-expanded="false"></button>
            </div>
        `;

        expect(applyHardwareStuckSummaryHtml(host, html)).toBe(true);
        const trigger = document.querySelector('[data-hardware-stuck-breakdown-trigger]');
        trigger?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(document.querySelector('[data-hardware-stuck-breakdown]')?.classList
            .contains('admin-hardware-stuck-kpi--breakdown-open')).toBe(true);
    });
});