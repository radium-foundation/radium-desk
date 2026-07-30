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
                        <div data-team-activity-row-toggle
                             role="button"
                             tabindex="0"
                             aria-expanded="${expanded ? 'true' : 'false'}">
                            <span class="agent-version">${version}</span>
                            <span class="team-activity-chevron" aria-hidden="true"></span>
                        </div>
                        <div data-team-activity-history ${expanded ? '' : 'hidden'}></div>
                    </li>
                </ul>
            </div>
            <button type="button" data-team-activity-panel-toggle aria-expanded="true">Toggle</button>
        </div>
    `;

    it('expands a member row when clicking anywhere on the summary', () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1')}</div>`;

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);
        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();

        const row = pageRoot.querySelector('[data-team-activity-agent="7"]');
        const summary = pageRoot.querySelector('[data-team-activity-row-toggle]');

        expect(row.dataset.teamActivityExpanded).toBe('0');

        summary.querySelector('.agent-version').dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(row.dataset.teamActivityExpanded).toBe('1');
        expect(summary.getAttribute('aria-expanded')).toBe('true');
        expect(row.classList.contains('is-expanded')).toBe(true);
    });

    it('expands a member row via keyboard on the summary', () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1')}</div>`;

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);
        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();

        const row = pageRoot.querySelector('[data-team-activity-agent="7"]');
        const summary = pageRoot.querySelector('[data-team-activity-row-toggle]');

        summary.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));

        expect(row.dataset.teamActivityExpanded).toBe('1');
        expect(summary.getAttribute('aria-expanded')).toBe('true');
    });

    it('does not expand when clicking an interactive control inside the row', () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1')}</div>`;

        const pageRoot = document.getElementById('dashboard-page');
        const summary = pageRoot.querySelector('[data-team-activity-row-toggle]');
        const control = document.createElement('button');
        control.type = 'button';
        control.textContent = 'Inline';
        summary.appendChild(control);

        initDashboardTeamActivity(pageRoot);
        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();

        const row = pageRoot.querySelector('[data-team-activity-agent="7"]');
        control.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(row.dataset.teamActivityExpanded).toBe('0');
    });

    it('starts collapsed by default', () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1')}</div>`;

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);

        const panel = pageRoot.querySelector('[data-team-activity-panel]');
        const toggle = pageRoot.querySelector('[data-team-activity-panel-toggle]');

        expect(panel.classList.contains('is-collapsed')).toBe(true);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
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
