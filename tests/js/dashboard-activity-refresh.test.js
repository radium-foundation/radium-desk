import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    initDashboardActivityRefresh,
    resetDashboardActivityRefreshStateForTests,
} from '../../resources/js/dashboard-activity-refresh';

describe('dashboard activity refresh', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        resetDashboardActivityRefreshStateForTests();
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
        vi.useRealTimers();
        resetDashboardActivityRefreshStateForTests();
    });

    it('polls the activity endpoint and replaces the feed when html changes', async () => {
        const initialHtml = `
            <div data-dashboard-activity-feed
                 data-activity-refresh-url="/dashboard/activity"
                 data-activity-poll-interval-ms="30000"
                 aria-label="My Activity">
                <span class="activity-version">v1</span>
            </div>
        `;
        const updatedHtml = `
            <div data-dashboard-activity-feed
                 data-activity-refresh-url="/dashboard/activity"
                 data-activity-poll-interval-ms="30000"
                 aria-label="My Activity">
                <span class="activity-version">v2</span>
            </div>
        `;

        document.body.innerHTML = `<div id="dashboard-page">${initialHtml}</div>`;

        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                empty: false,
                html: updatedHtml,
            }),
        });

        vi.stubGlobal('fetch', fetchMock);

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardActivityRefresh(pageRoot);

        await vi.advanceTimersByTimeAsync(30000);

        expect(fetchMock).toHaveBeenCalledWith('/dashboard/activity', expect.objectContaining({
            credentials: 'same-origin',
        }));
        expect(pageRoot.querySelector('.activity-version')?.textContent).toBe('v2');
    });

    it('does not start polling when the feed is missing', () => {
        document.body.innerHTML = '<div id="dashboard-page"></div>';

        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        const pageRoot = document.getElementById('dashboard-page');
        const handle = initDashboardActivityRefresh(pageRoot);

        expect(handle).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
