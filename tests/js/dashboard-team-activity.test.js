import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    initDashboardTeamActivity,
    resetDashboardTeamActivityStateForTests,
} from '../../resources/js/dashboard-team-activity';

describe('dashboard team activity', () => {
    const panelHtml = (version = 'v1', expanded = false, { lazy = false } = {}) => `
        <div class="team-activity-panel"
             data-team-activity-panel
             data-team-activity-refresh-url="/dashboard/team-activity"
             data-team-activity-poll-interval-ms="60000"
             data-team-activity-user-idle-ms="300000"
             data-team-activity-collapsed="0"
             ${lazy ? 'data-team-activity-lazy="1"' : ''}
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
                ${lazy ? '' : `
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
                `}
            </div>
        </div>
    `;

    beforeEach(() => {
        vi.useFakeTimers();
        resetDashboardTeamActivityStateForTests();
        sessionStorage.clear();
        vi.stubEnv('DEV', true);
        // Default stub prevents accidental real network from expand/poll in UI-only tests.
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                empty: false,
                html: panelHtml('default'),
            }),
        }));
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
        vi.unstubAllEnvs();
        vi.useRealTimers();
        resetDashboardTeamActivityStateForTests();
        sessionStorage.clear();
    });

    const flushMicrotasks = async () => {
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();
    };

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

    it('collapses and expands again without losing interactions', async () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1', false, { lazy: true })}</div>`;

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

        const toggle = () => pageRoot.querySelector('[data-team-activity-panel-toggle]');

        toggle().click();
        await flushMicrotasks();

        expect(pageRoot.querySelector('[data-team-activity-panel]')?.classList.contains('is-collapsed')).toBe(false);
        expect(pageRoot.querySelector('.agent-version')?.textContent).toBe('v2');

        toggle().click();
        expect(pageRoot.querySelector('[data-team-activity-panel]')?.classList.contains('is-collapsed')).toBe(true);

        fetchMock.mockClear();
        toggle().click();
        await flushMicrotasks();

        expect(pageRoot.querySelector('[data-team-activity-panel]')?.classList.contains('is-collapsed')).toBe(false);
        expect(fetchMock).toHaveBeenCalled();
        expect(pageRoot.querySelector('.agent-version')?.textContent).toBe('v2');
    });

    it('expands a member row when clicking the row chevron', async () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1')}</div>`;

        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                empty: false,
                html: panelHtml('v1'),
            }),
        });
        vi.stubGlobal('fetch', fetchMock);

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);
        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();
        await flushMicrotasks();

        const row = pageRoot.querySelector('[data-team-activity-agent="7"]');
        const toggle = pageRoot.querySelector('[data-team-activity-row-toggle]');

        expect(row.dataset.teamActivityExpanded).toBe('0');

        toggle.click();

        expect(row.dataset.teamActivityExpanded).toBe('1');
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect(row.classList.contains('is-expanded')).toBe(true);
    });

    it('does not expand a member row when clicking row content outside the chevron', async () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1')}</div>`;

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ empty: false, html: panelHtml('v1') }),
        }));

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);
        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();
        await flushMicrotasks();

        const row = pageRoot.querySelector('[data-team-activity-agent="7"]');
        pageRoot.querySelector('.agent-version').dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(row.dataset.teamActivityExpanded).toBe('0');
    });

    it('loads team activity immediately when the panel expands', async () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1', false, { lazy: true })}</div>`;

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

        expect(fetchMock).not.toHaveBeenCalled();

        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();
        await flushMicrotasks();

        expect(fetchMock).toHaveBeenCalled();
        const calledUrl = String(fetchMock.mock.calls[0][0]);
        expect(calledUrl).toContain('/dashboard/team-activity');
        expect(pageRoot.querySelector('.agent-version')?.textContent).toBe('v2');
        expect(pageRoot.querySelector('[data-team-activity-panel]')).not.toBeNull();
    });

    it('hydrates immediately when session restores the panel expanded', async () => {
        sessionStorage.setItem('radium.teamActivityPanel.collapsed', '0');
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('shell', false, { lazy: true })}</div>`;

        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                empty: false,
                html: panelHtml('hydrated'),
            }),
        });

        vi.stubGlobal('fetch', fetchMock);

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);
        await flushMicrotasks();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(pageRoot.querySelector('.agent-version')?.textContent).toBe('hydrated');
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

        await flushMicrotasks();
        fetchMock.mockClear();

        await vi.advanceTimersByTimeAsync(60000);

        expect(fetchMock).toHaveBeenCalled();
        const calledUrl = String(fetchMock.mock.calls[0][0]);
        expect(calledUrl).toContain('/dashboard/team-activity');
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

    it('keeps the shell and shows retry UI when hydrate fails', async () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1', false, { lazy: true })}</div>`;

        const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const fetchMock = vi.fn().mockResolvedValue({
            ok: false,
            status: 500,
            json: async () => ({}),
        });

        vi.stubGlobal('fetch', fetchMock);

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);
        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();
        await flushMicrotasks();

        const panel = pageRoot.querySelector('[data-team-activity-panel]');
        expect(panel).not.toBeNull();
        expect(panel.classList.contains('is-collapsed')).toBe(false);
        expect(panel.querySelector('[data-team-activity-hydrate-error]')).not.toBeNull();
        expect(panel.textContent).toContain('Unable to load team activity. Retry.');
        expect(panel.querySelector('[data-team-activity-retry]')).not.toBeNull();
        expect(warnSpy).toHaveBeenCalled();
    });

    it('retries hydrate when clicking the Retry button', async () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1', false, { lazy: true })}</div>`;

        const fetchMock = vi.fn()
            .mockResolvedValueOnce({
                ok: false,
                status: 503,
                json: async () => ({}),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    empty: false,
                    html: panelHtml('recovered'),
                }),
            });

        vi.stubGlobal('fetch', fetchMock);

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);
        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();
        await flushMicrotasks();

        expect(pageRoot.querySelector('[data-team-activity-hydrate-error]')).not.toBeNull();

        pageRoot.querySelector('[data-team-activity-retry]').click();
        await flushMicrotasks();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(pageRoot.querySelector('.agent-version')?.textContent).toBe('recovered');
        expect(pageRoot.querySelector('[data-team-activity-hydrate-error]')).toBeNull();
        expect(pageRoot.querySelector('[data-team-activity-panel]')).not.toBeNull();
    });

    it('keeps the shell for genuine empty roster and does not remove the panel', async () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1', false, { lazy: true })}</div>`;

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                empty: true,
                html: null,
                reason: 'roster_empty',
            }),
        }));

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);
        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();
        await flushMicrotasks();

        const panel = pageRoot.querySelector('[data-team-activity-panel]');
        expect(panel).not.toBeNull();
        expect(panel.querySelector('[data-team-activity-empty-roster]')).not.toBeNull();
        expect(panel.textContent).toContain('No team members to show.');
        expect(panel.querySelector('[data-team-activity-hydrate-error]')).toBeNull();
    });

    it('does not remove the panel or wipe roster when a later poll fails', async () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1')}</div>`;

        const fetchMock = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    empty: false,
                    html: panelHtml('stable'),
                }),
            })
            .mockResolvedValue({
                ok: false,
                status: 500,
                json: async () => ({}),
            });

        vi.stubGlobal('fetch', fetchMock);

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardTeamActivity(pageRoot);
        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();
        await flushMicrotasks();

        expect(pageRoot.querySelector('.agent-version')?.textContent).toBe('stable');

        await vi.advanceTimersByTimeAsync(60000);
        await flushMicrotasks();

        expect(pageRoot.querySelector('[data-team-activity-panel]')).not.toBeNull();
        expect(pageRoot.querySelector('.agent-version')?.textContent).toBe('stable');
        expect(pageRoot.querySelector('[data-team-activity-hydrate-error]')).toBeNull();
    });

    it('does not bind duplicate panel click listeners after replaceWith', async () => {
        document.body.innerHTML = `<div id="dashboard-page">${panelHtml('v1', false, { lazy: true })}</div>`;

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
        await flushMicrotasks();

        const panel = pageRoot.querySelector('[data-team-activity-panel]');
        expect(panel.dataset.teamActivityBound).toBe('1');

        // Second expand cycle replaces HTML again; bound flag must prevent stacking.
        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();
        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();
        await flushMicrotasks();

        const nextPanel = pageRoot.querySelector('[data-team-activity-panel]');
        expect(nextPanel.dataset.teamActivityBound).toBe('1');

        const addSpy = vi.spyOn(nextPanel, 'addEventListener');
        // Re-binding the same node must no-op.
        initDashboardTeamActivity(pageRoot);
        // destroy + re-init binds once on the current panel.
        expect(pageRoot.querySelector('[data-team-activity-panel]')?.dataset.teamActivityBound).toBe('1');
        addSpy.mockRestore();
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
        pageRoot.querySelector('[data-team-activity-panel-toggle]').click();
        await flushMicrotasks();
        fetchMock.mockClear();

        await vi.advanceTimersByTimeAsync(60000);

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
