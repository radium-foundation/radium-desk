import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    initDashboardTeamActivity,
    resetDashboardTeamActivityStateForTests,
} from '../../resources/js/dashboard-team-activity';

describe('dashboard team activity', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        resetDashboardTeamActivityStateForTests();
        sessionStorage.clear();
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
        vi.useRealTimers();
        resetDashboardTeamActivityStateForTests();
        sessionStorage.clear();
    });

    const panelHtml = (version = 'v1', expanded = false) => `
        <div data-team-activity-panel
             data-team-activity-refresh-url="/dashboard/team-activity"
             data-team-activity-poll-interval-ms="30000"
             data-team-activity-user-idle-ms="300000"
             data-team-activity-collapsed="0"
             aria-label="Team Activity">
            <div data-team-activity-panel-body>
                <ul data-team-activity-list>
                    <li data-team-activity-agent="7"
                        data-team-activity-expanded="${expanded ? '1' : '0'}"
                        class="${expanded ? 'is-expanded' : ''}">
                        <button type="button" data-team-activity-row-toggle aria-expanded="${expanded ? 'true' : 'false'}">
                            <span class="agent-version">${version}</span>
                        </button>
                        <div data-team-activity-history ${expanded ? '' : 'hidden'}></div>
                    </li>
                </ul>
            </div>
            <button type="button" data-team-activity-panel-toggle aria-expanded="true">Toggle</button>
        </div>
    `;

    it('polls the team activity endpoint when the panel is open', async () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1')}</div>`;

        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                empty: false,
                html: panelHtml('v2'),
            }),
        });

        vi.stubGlobal('fetch', fetchMock);

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);

        await vi.advanceTimersByTimeAsync(30000);

        expect(fetchMock).toHaveBeenCalled();
        const calledUrl = String(fetchMock.mock.calls[0][0]);
        expect(calledUrl).toContain('/dashboard/team-activity');
        expect(pageRoot.querySelector('.agent-version')?.textContent).toBe('v2');
    });

    it('does not poll when the panel is collapsed', async () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1')}</div>`;

        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);

        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();

        await vi.advanceTimersByTimeAsync(60000);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('does not poll when the document is hidden', async () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1')}</div>`;

        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        Object.defineProperty(document, 'hidden', {
            configurable: true,
            get: () => true,
        });

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);

        await vi.advanceTimersByTimeAsync(30000);

        expect(fetchMock).not.toHaveBeenCalled();

        Object.defineProperty(document, 'hidden', {
            configurable: true,
            get: () => false,
        });
    });

    it('does not start when the panel is missing', () => {
        document.body.innerHTML = '<div id="dashboard-page"></div>';

        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        const handle = initDashboardTeamActivity(document.getElementById('dashboard-page'));

        expect(handle).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
