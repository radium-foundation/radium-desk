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
            <div class="team-activity-panel-header">
                <button type="button"
                        class="team-activity-panel-header-toggle"
                        data-team-activity-panel-toggle
                        aria-expanded="true"
                        aria-label="Collapse Team Activity">
                    <span class="team-activity-panel-title">Team Activity</span>
                    <span class="team-activity-panel-chevron" aria-hidden="true"></span>
                </button>
            </div>
            <div data-team-activity-panel-body>
                <ul data-team-activity-list>
                    <li data-team-activity-agent="7"
                        data-team-activity-expanded="${expanded ? '1' : '0'}"
                        class="${expanded ? 'is-expanded' : ''}">
                        <div class="team-activity-row-summary">
                            <span class="agent-version">${version}</span>
                            <button type="button"
                                    data-team-activity-row-toggle
                                    aria-expanded="${expanded ? 'true' : 'false'}">
                                <span class="team-activity-chevron" aria-hidden="true"></span>
                            </button>
                        </div>
                        <div data-team-activity-history ${expanded ? '' : 'hidden'}></div>
                    </li>
                </ul>
            </div>
        </div>
    `;

    it('starts collapsed by default', () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1')}</div>`;

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);

        const panel = pageRoot.querySelector('[data-team-activity-panel]');
        const toggle = pageRoot.querySelector('[data-team-activity-panel-toggle]');

        expect(panel.classList.contains('is-collapsed')).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
    });

    it('expands the panel when clicking the Team Activity title', () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1')}</div>`;

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);

        const panel = pageRoot.querySelector('[data-team-activity-panel]');
        const title = pageRoot.querySelector('.team-activity-panel-title');

        expect(panel.classList.contains('is-collapsed')).toBe(true);

        title.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(panel.classList.contains('is-collapsed')).toBe(false);
        expect(pageRoot.querySelector('[data-team-activity-panel-toggle]').getAttribute('aria-expanded')).toBe('true');
    });

    it('expands the panel when clicking the panel chevron', () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1')}</div>`;

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);

        const panel = pageRoot.querySelector('[data-team-activity-panel]');
        const chevron = pageRoot.querySelector('.team-activity-panel-chevron');

        chevron.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(panel.classList.contains('is-collapsed')).toBe(false);
    });

    it('expands a member row when clicking the row chevron', () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1')}</div>`;

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);
        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();

        const row = pageRoot.querySelector('[data-team-activity-agent="7"]');
        const toggle = pageRoot.querySelector('[data-team-activity-row-toggle]');

        expect(row.dataset.teamActivityExpanded).toBe('0');

        toggle.click();

        expect(row.dataset.teamActivityExpanded).toBe('1');
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect(row.classList.contains('is-expanded')).toBe(true);
    });

    it('does not expand a member row when clicking row content outside the chevron', () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1')}</div>`;

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);
        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();

        const row = pageRoot.querySelector('[data-team-activity-agent="7"]');
        pageRoot.querySelector('.agent-version').dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(row.dataset.teamActivityExpanded).toBe('0');
    });

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
        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();

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

        await vi.advanceTimersByTimeAsync(60000);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('collapses when clicking outside the panel', () => {
        document.body.innerHTML = `
            <div id="dashboard-page">
                <button type="button" id="outside-action">Outside</button>
                ${panelHtml('v1')}
            </div>
        `;

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);

        const panel = pageRoot.querySelector('[data-team-activity-panel]');
        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();

        expect(panel.classList.contains('is-collapsed')).toBe(false);

        pageRoot.querySelector('#outside-action').click();

        expect(panel.classList.contains('is-collapsed')).toBe(true);
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
