import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    handleKpisUpdated,
    handleServiceCaseEvent,
    resolveListAction,
} from '../../resources/js/live-dashboard-reverb';
import { getWorkspaceSession, resetWorkspaceSession } from '../../resources/js/workspace/session';

describe('live dashboard reverb handlers', () => {
    beforeEach(() => {
        resetWorkspaceSession();
        document.body.innerHTML = `
            <div id="dashboard-page" data-live-queue="action_required" data-live-filter="pending_admin"></div>
            <div id="dashboard-kpi-strip">stats-old</div>
            <div class="dashboard-service-cases-card">
                <span data-dashboard-case-filter-count="action_required">(0)</span>
                <span data-dashboard-case-filter-count="waiting_customer">(0)</span>
                <div id="dashboard-service-cases-scroll">
                    <table>
                        <thead><tr><th>Ref</th></tr></thead>
                        <tbody id="dashboard-service-cases-body">
                            <tr id="service-case-row-10"><td>SC00010</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    });

    afterEach(() => {
        resetWorkspaceSession();
    });

    it('updates KPI strip from DashboardKpisUpdated payload', async () => {
        await handleKpisUpdated({ kpi_strip_html: 'stats-live' });

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-live');
    });

    it('updates queue filter counts from DashboardKpisUpdated payload', async () => {
        await handleKpisUpdated({
            kpi_strip_html: 'stats-live',
            service_case_filter_count_variants: {
                operations_scope: {
                    action_required: 12,
                    waiting_customer: 4,
                },
            },
        });

        expect(document.querySelector('[data-dashboard-case-filter-count="action_required"]')?.textContent).toBe('(12)');
        expect(document.querySelector('[data-dashboard-case-filter-count="waiting_customer"]')?.textContent).toBe('(4)');
    });

    it('applies support scope counts when the active tab uses support_scope', async () => {
        document.getElementById('dashboard-page').dataset.liveScope = 'support_scope';

        await handleKpisUpdated({
            kpi_strip_html: 'stats-live',
            service_case_filter_count_variants: {
                support_scope: {
                    waiting_customer: 7,
                },
                operations_scope: {
                    waiting_customer: 99,
                },
            },
        });

        expect(document.querySelector('[data-dashboard-case-filter-count="waiting_customer"]')?.textContent).toBe('(7)');
    });

    it('merges a service case row when list action is add', async () => {
        const pageRoot = document.getElementById('dashboard-page');

        await handleServiceCaseEvent(pageRoot, {
            incident_id: 10,
            queue: 'action_required',
            list_actions: {
                action_required: 'add',
            },
            html: '<tr id="service-case-row-10"><td>SC00010 updated</td></tr>',
        });

        expect(document.querySelector('#service-case-row-10 td')?.textContent).toBe('SC00010 updated');
    });

    it('removes rows when list action is remove for the active queue', async () => {
        const pageRoot = document.getElementById('dashboard-page');

        await handleServiceCaseEvent(pageRoot, {
            incident_id: 10,
            queue: 'waiting_customer',
            list_actions: {
                action_required: 'remove',
            },
        });

        expect(document.querySelector('#service-case-row-10')).toBeNull();
    });

    it('ignores row updates when list action is ignore for the active queue', async () => {
        const pageRoot = document.getElementById('dashboard-page');

        await handleServiceCaseEvent(pageRoot, {
            incident_id: 10,
            queue: 'waiting_customer',
            list_actions: {
                waiting_customer: 'add',
            },
            html: '<tr id="service-case-row-10"><td>Should not apply</td></tr>',
        });

        expect(document.querySelector('#service-case-row-10 td')?.textContent).toBe('SC00010');
    });

    it('ignores row updates for locked incidents', async () => {
        const pageRoot = document.getElementById('dashboard-page');
        const session = getWorkspaceSession();

        session.acquire('inline-transaction', { incidentId: 10 });

        await handleServiceCaseEvent(pageRoot, {
            incident_id: 10,
            queue: 'action_required',
            list_actions: {
                action_required: 'update',
            },
            html: '<tr id="service-case-row-10"><td>Should not apply</td></tr>',
        });

        expect(document.querySelector('#service-case-row-10 td')?.textContent).toBe('SC00010');
    });

    it('queues updates while workspace session is active', async () => {
        const pageRoot = document.getElementById('dashboard-page');
        const session = getWorkspaceSession();

        session.acquire('workspace-modal');

        await handleKpisUpdated({ kpi_strip_html: 'stats-deferred' });

        expect(document.getElementById('dashboard-kpi-strip')?.textContent).toBe('stats-old');
    });

    it('resolves list action from the active queue tab', () => {
        const pageRoot = document.getElementById('dashboard-page');

        expect(resolveListAction(pageRoot, {
            list_actions: {
                action_required: 'remove',
                waiting_customer: 'add',
            },
        })).toBe('remove');

        pageRoot.dataset.liveQueue = 'waiting_customer';

        expect(resolveListAction(pageRoot, {
            list_actions: {
                action_required: 'remove',
                waiting_customer: 'add',
            },
        })).toBe('add');
    });
});

describe('live dashboard reverb reconnect fallback', () => {
    it('exports initLiveDashboardReverb for wiring', async () => {
        const module = await import('../../resources/js/live-dashboard-reverb');

        expect(typeof module.initLiveDashboardReverb).toBe('function');
    });
});
