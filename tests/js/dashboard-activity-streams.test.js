import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initDashboardActivityStreams } from '../../resources/js/dashboard-activity-streams';

describe('dashboard activity streams', () => {
    beforeEach(() => {
        vi.stubGlobal('sessionStorage', {
            store: {},
            getItem(key) {
                return this.store[key] ?? null;
            },
            setItem(key, value) {
                this.store[key] = String(value);
            },
            removeItem(key) {
                delete this.store[key];
            },
        });

        document.body.innerHTML = `
            <div id="dashboard-page">
                <div data-dashboard-activity-feed>
                    <section class="dashboard-activity-stream" data-dashboard-activity-stream="team" data-collapsed-default="0">
                        <button type="button" data-dashboard-activity-stream-toggle aria-expanded="true">Team</button>
                        <ul data-dashboard-activity-stream-panel>
                            <li data-activity-thread data-activity-thread-incident="42">
                                <div data-dashboard-activity-entry data-incident-id="42">row</div>
                                <button type="button" data-activity-thread-toggle aria-expanded="false">
                                    <span data-activity-thread-toggle-label>History</span>
                                </button>
                                <div data-activity-thread-history hidden>older</div>
                            </li>
                        </ul>
                    </section>
                    <section class="dashboard-activity-stream is-collapsed" data-dashboard-activity-stream="ira" data-collapsed-default="1">
                        <button type="button" data-dashboard-activity-stream-toggle aria-expanded="false">IRA</button>
                        <ul data-dashboard-activity-stream-panel hidden><li>x</li></ul>
                    </section>
                </div>
            </div>
        `;
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('toggles stream collapse via delegated click and persists state', () => {
        const root = document.getElementById('dashboard-page');
        const api = initDashboardActivityStreams(root);
        const team = document.querySelector('[data-dashboard-activity-stream="team"]');
        const panel = team.querySelector('[data-dashboard-activity-stream-panel]');
        const toggle = team.querySelector('[data-dashboard-activity-stream-toggle]');

        toggle.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(team.classList.contains('is-collapsed')).toBe(true);
        expect(panel.hidden).toBe(true);
        expect(sessionStorage.getItem('radium.dashboardActivityStream.team')).toBe('1');

        toggle.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(team.classList.contains('is-collapsed')).toBe(false);
        expect(panel.hidden).toBe(false);

        api?.destroy();
    });

    it('toggles thread history without requiring per-element rebinding', () => {
        const root = document.getElementById('dashboard-page');
        initDashboardActivityStreams(root);

        const thread = document.querySelector('[data-activity-thread]');
        const history = thread.querySelector('[data-activity-thread-history]');
        const toggle = thread.querySelector('[data-activity-thread-toggle]');

        toggle.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(thread.classList.contains('is-expanded')).toBe(true);
        expect(history.hidden).toBe(false);
        expect(sessionStorage.getItem('radium.dashboardActivityThread.42')).toBe('1');
    });

    it('does not double-toggle when init runs twice', () => {
        const root = document.getElementById('dashboard-page');
        initDashboardActivityStreams(root);
        initDashboardActivityStreams(root);

        const team = document.querySelector('[data-dashboard-activity-stream="team"]');
        const toggle = team.querySelector('[data-dashboard-activity-stream-toggle]');

        toggle.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(team.classList.contains('is-collapsed')).toBe(true);
        expect(team.querySelector('[data-dashboard-activity-stream-panel]').hidden).toBe(true);
    });
});
