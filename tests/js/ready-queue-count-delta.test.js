import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    READY_QUEUE,
    applyReadyQueueCountDelta,
    commitReadyQueueListActionDelta,
    resetReadyQueueCountDeltaForTests,
    resolveReadyQueueCountDeltaFromHybrid,
    resolveReadyQueueCountDeltaFromListAction,
    syncReadyQueueMembershipScope,
} from '../../resources/js/ready-queue-count-delta';
import { applyFilterCounts, readFilterCount } from '../../resources/js/live-dashboard';

describe('ready queue count delta', () => {
    beforeEach(() => {
        resetReadyQueueCountDeltaForTests();
        document.body.innerHTML = `
            <div id="dashboard-page" data-live-queue="action_required"></div>
            <div class="dashboard-service-cases-card">
                <span data-dashboard-case-filter-count="action_required">(10)</span>
                <span data-dashboard-case-filter-count="waiting_customer">(3)</span>
                <table>
                    <tbody id="dashboard-service-cases-body">
                        <tr id="service-case-row-10"><td>SC00010</td></tr>
                    </tbody>
                </table>
            </div>
        `;
    });

    afterEach(() => {
        resetReadyQueueCountDeltaForTests();
    });

    it('A: Ready Queue ADD changes 10 → 11', () => {
        const pageRoot = document.getElementById('dashboard-page');
        const resolution = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 99,
            listActions: { action_required: 'add' },
        });

        expect(resolution).toMatchObject({ safe: true, delta: 1, reason: 'add' });
        commitReadyQueueListActionDelta(resolution, 99);

        expect(readFilterCount(READY_QUEUE)).toBe(11);
    });

    it('B: Ready Queue REMOVE changes 10 → 9', () => {
        const pageRoot = document.getElementById('dashboard-page');
        const resolution = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 10,
            listActions: { action_required: 'remove' },
        });

        expect(resolution).toMatchObject({ safe: true, delta: -1, reason: 'remove' });
        commitReadyQueueListActionDelta(resolution, 10);

        expect(readFilterCount(READY_QUEUE)).toBe(9);
    });

    it('C: Ready Queue UPDATE remains 10', () => {
        const pageRoot = document.getElementById('dashboard-page');

        const viaUpdate = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 10,
            listActions: { action_required: 'update' },
        });
        expect(viaUpdate).toMatchObject({ safe: true, delta: 0, reason: 'update' });
        commitReadyQueueListActionDelta(viaUpdate, 10);

        const viaAddPresent = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 10,
            listActions: { action_required: 'add' },
        });
        expect(viaAddPresent).toMatchObject({
            safe: true,
            delta: 0,
            reason: 'add_already_present',
        });
        commitReadyQueueListActionDelta(viaAddPresent, 10);

        expect(readFilterCount(READY_QUEUE)).toBe(10);
    });

    it('D: event affecting another queue does not change Ready Queue count', () => {
        const pageRoot = document.getElementById('dashboard-page');
        pageRoot.dataset.liveQueue = 'waiting_customer';

        const resolution = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 99,
            listActions: {
                waiting_customer: 'add',
                action_required: 'add',
            },
        });

        expect(resolution.safe).toBe(false);
        expect(resolution.reason).toBe('not_viewing_ready');
        commitReadyQueueListActionDelta(resolution, 99);

        expect(readFilterCount(READY_QUEUE)).toBe(10);
        expect(readFilterCount('waiting_customer')).toBe(3);
    });

    it('E: duplicate event cannot double increment or decrement', () => {
        const pageRoot = document.getElementById('dashboard-page');

        const firstAdd = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 99,
            listActions: { action_required: 'add' },
        });
        commitReadyQueueListActionDelta(firstAdd, 99);
        expect(readFilterCount(READY_QUEUE)).toBe(11);

        // Simulate row now present after patch.
        document.getElementById('dashboard-service-cases-body')
            .insertAdjacentHTML('beforeend', '<tr id="service-case-row-99"><td>SC00099</td></tr>');

        const secondAdd = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 99,
            listActions: { action_required: 'add' },
        });
        expect(secondAdd.delta).toBe(0);
        commitReadyQueueListActionDelta(secondAdd, 99);
        expect(readFilterCount(READY_QUEUE)).toBe(11);

        const firstRemove = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 99,
            listActions: { action_required: 'remove' },
        });
        commitReadyQueueListActionDelta(firstRemove, 99);
        expect(readFilterCount(READY_QUEUE)).toBe(10);

        document.getElementById('service-case-row-99')?.remove();

        const secondRemove = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 99,
            listActions: { action_required: 'remove' },
        });
        expect(secondRemove).toMatchObject({ safe: true, delta: 0, reason: 'duplicate_remove' });
        commitReadyQueueListActionDelta(secondRemove, 99);
        expect(readFilterCount(READY_QUEUE)).toBe(10);
    });

    it('F: absolute counts-only reconciliation still overwrites the chip', () => {
        applyReadyQueueCountDelta(1, { rememberIn: [99] });
        expect(readFilterCount(READY_QUEUE)).toBe(11);

        applyFilterCounts({ action_required: 7, waiting_customer: 3 });

        expect(readFilterCount(READY_QUEUE)).toBe(7);
        expect(readFilterCount('waiting_customer')).toBe(3);
    });

    it('hybrid REMOVE/ADD while viewing Ready proves deltas before DOM mutation', () => {
        const pageRoot = document.getElementById('dashboard-page');

        const removeResolution = resolveReadyQueueCountDeltaFromHybrid({
            pageRoot,
            rows: [],
            removeIncidentIds: [10],
        });
        expect(removeResolution).toMatchObject({ safe: true, delta: -1 });

        const addResolution = resolveReadyQueueCountDeltaFromHybrid({
            pageRoot,
            rows: [{ incident_id: 55, html: '<tr id="service-case-row-55"></tr>' }],
            removeIncidentIds: [],
        });
        expect(addResolution).toMatchObject({ safe: true, delta: 1 });
    });

    it('P1: ADD → switch away → absolute reconcile → return → REMOVE does not use stale memory', () => {
        const pageRoot = document.getElementById('dashboard-page');

        const add = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 99,
            listActions: { action_required: 'add' },
        });
        commitReadyQueueListActionDelta(add, 99);
        expect(readFilterCount(READY_QUEUE)).toBe(11);

        pageRoot.dataset.liveQueue = 'waiting_customer';
        syncReadyQueueMembershipScope(pageRoot);
        document.getElementById('dashboard-service-cases-body').innerHTML = '';

        // Absolute reconcile while away (kpis_only / DashboardKpisUpdated / full live).
        applyFilterCounts({ action_required: 10, waiting_customer: 4 });
        expect(readFilterCount(READY_QUEUE)).toBe(10);

        pageRoot.dataset.liveQueue = 'action_required';
        syncReadyQueueMembershipScope(pageRoot);

        const remove = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 99,
            listActions: { action_required: 'remove' },
        });

        expect(remove).toMatchObject({
            safe: false,
            delta: 0,
            reason: 'remove_not_in_dom',
        });
        commitReadyQueueListActionDelta(remove, 99);
        expect(readFilterCount(READY_QUEUE)).toBe(10);
    });

    it('P1: ADD → switch away → return → ADD is not suppressed by stale in memory', () => {
        const pageRoot = document.getElementById('dashboard-page');

        const firstAdd = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 99,
            listActions: { action_required: 'add' },
        });
        commitReadyQueueListActionDelta(firstAdd, 99);
        expect(readFilterCount(READY_QUEUE)).toBe(11);

        pageRoot.dataset.liveQueue = 'waiting_customer';
        syncReadyQueueMembershipScope(pageRoot);
        document.getElementById('dashboard-service-cases-body').innerHTML = '';
        // No absolute overwrite — queue switch alone must clear membership memory.
        pageRoot.dataset.liveQueue = 'action_required';
        syncReadyQueueMembershipScope(pageRoot);

        const secondAdd = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 99,
            listActions: { action_required: 'add' },
        });

        expect(secondAdd).toMatchObject({ safe: true, delta: 1, reason: 'add' });
        commitReadyQueueListActionDelta(secondAdd, 99);
        expect(readFilterCount(READY_QUEUE)).toBe(12);
    });

    it('P1: optimistic ±1 then absolute overwrite clears memory for the next event', () => {
        const pageRoot = document.getElementById('dashboard-page');

        const add = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 99,
            listActions: { action_required: 'add' },
        });
        commitReadyQueueListActionDelta(add, 99);
        expect(readFilterCount(READY_QUEUE)).toBe(11);

        applyFilterCounts({ action_required: 8 });
        expect(readFilterCount(READY_QUEUE)).toBe(8);

        // Still on Ready, row absent — must not treat stale memory as membership.
        const remove = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 99,
            listActions: { action_required: 'remove' },
        });
        expect(remove).toMatchObject({
            safe: false,
            delta: 0,
            reason: 'remove_not_in_dom',
        });

        const reAdd = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 99,
            listActions: { action_required: 'add' },
        });
        expect(reAdd).toMatchObject({ safe: true, delta: 1, reason: 'add' });
    });

    it('UPDATE while remaining on Ready does not clear membership memory', () => {
        const pageRoot = document.getElementById('dashboard-page');

        const update = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 10,
            listActions: { action_required: 'update' },
        });
        commitReadyQueueListActionDelta(update, 10);

        document.getElementById('service-case-row-10')?.remove();

        // Memory from UPDATE should still prove prior membership for REMOVE.
        const remove = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 10,
            listActions: { action_required: 'remove' },
        });
        expect(remove).toMatchObject({ safe: true, delta: -1, reason: 'remove' });
    });

    it('unsafe REMOVE stays unsafe so caller can schedule kpisOnly reconcile', () => {
        const pageRoot = document.getElementById('dashboard-page');
        document.getElementById('service-case-row-10')?.remove();

        const remove = resolveReadyQueueCountDeltaFromListAction({
            pageRoot,
            incidentId: 10,
            listActions: { action_required: 'remove' },
        });

        expect(remove).toMatchObject({
            safe: false,
            delta: 0,
            reason: 'remove_not_in_dom',
        });
    });
});
