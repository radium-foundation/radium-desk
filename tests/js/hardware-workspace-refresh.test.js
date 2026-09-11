import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../resources/js/workspace/http', () => ({
    workspaceFetchHeaders: () => ({ Accept: 'application/json' }),
    workspaceFetch: vi.fn(),
}));

import { workspaceFetch } from '../../resources/js/workspace/http';
import {
    isHardwareWorkspaceActive,
    refreshHardwareWorkspace,
} from '../../resources/js/hardware-workspace-refresh';

describe('hardware-workspace-refresh', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.clearAllMocks();
    });

    it('detects active hardware workspace', () => {
        const pageRoot = document.createElement('div');
        pageRoot.dataset.liveQueue = 'hardware';

        expect(isHardwareWorkspaceActive(pageRoot)).toBe(true);
    });

    it('refreshes workspace html and counts from server payload', async () => {
        workspaceFetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                ok: true,
                workspace_html: '<div data-hardware-workspace><table><tbody><tr data-hardware-order="RDE1"></tr></tbody></table></div>',
                counts: { ready: 2, exceptions: 1, pickup: 0, completed: 0 },
                hardware_chip_count: 3,
            }),
        });

        document.body.innerHTML = `
            <div id="dashboard-page"
                 data-live-queue="hardware"
                 data-hardware-workspace-url="/dashboard/hardware-workspace">
                <div data-hardware-workspace>
                    <table><tbody><tr data-hardware-order="STALE"></tr></tbody></table>
                </div>
                <span data-dashboard-case-filter-count="hardware">(0)</span>
                <span data-hardware-queue-count="ready">(0)</span>
            </div>
        `;

        const pageRoot = document.getElementById('dashboard-page');
        const refreshed = await refreshHardwareWorkspace(pageRoot);

        expect(refreshed).toBe(true);
        expect(pageRoot.querySelector('[data-hardware-order="RDE1"]')).not.toBeNull();
        expect(pageRoot.querySelector('[data-hardware-order="STALE"]')).toBeNull();
        expect(pageRoot.querySelector('[data-dashboard-case-filter-count="hardware"]').textContent).toBe('(3)');
        expect(pageRoot.querySelector('[data-hardware-queue-count="ready"]').textContent).toBe('(2)');
    });

    it('does not refresh when endpoint fails', async () => {
        workspaceFetch.mockResolvedValue({
            ok: false,
            json: async () => ({ ok: false }),
        });

        document.body.innerHTML = `
            <div id="dashboard-page"
                 data-live-queue="hardware"
                 data-hardware-workspace-url="/dashboard/hardware-workspace">
                <div data-hardware-workspace><span id="stale">stale</span></div>
            </div>
        `;

        const pageRoot = document.getElementById('dashboard-page');
        const refreshed = await refreshHardwareWorkspace(pageRoot);

        expect(refreshed).toBe(false);
        expect(pageRoot.querySelector('#stale')).not.toBeNull();
    });
});
