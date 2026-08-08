import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    handleHybridIncidentsUpdated,
    handleServiceCaseEvent,
} from '../../resources/js/live-dashboard-reverb';
import { resetHybridKpiReconcileForTests } from '../../resources/js/hybrid-kpi-reconcile';
import { resetReadyQueueCountDeltaForTests } from '../../resources/js/ready-queue-count-delta';
import * as liveDashboard from '../../resources/js/live-dashboard';
import { resetWorkspaceSession } from '../../resources/js/workspace/session';

/**
 * Local Phase 2 micro-bench: proven Ready ADD/REMOVE must not schedule
 * kpis_only reconcile; unsafe paths still may.
 */
describe('ready queue phase 2 benchmark', () => {
    beforeEach(() => {
        resetWorkspaceSession();
        resetHybridKpiReconcileForTests();
        resetReadyQueueCountDeltaForTests();
        document.body.innerHTML = `
            <div id="dashboard-page"
                 data-live-queue="action_required"
                 data-live-rows-url="/dashboard/live/rows"
                 data-live-url="/dashboard/live"></div>
            <div id="dashboard-kpi-strip">stats</div>
            <div class="dashboard-service-cases-card">
                <span data-dashboard-case-filter-count="action_required">(10)</span>
                <div id="dashboard-service-cases-scroll">
                    <table>
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
        resetHybridKpiReconcileForTests();
        resetReadyQueueCountDeltaForTests();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('benchmark: proven ADD/REMOVE skip kpis_only; unsafe path still reconciles', async () => {
        vi.useFakeTimers();
        vi.stubGlobal('requestAnimationFrame', (callback) => {
            callback(0);

            return 1;
        });

        const refreshSpy = vi.spyOn(liveDashboard, 'refreshDashboard').mockResolvedValue(undefined);
        const pageRoot = document.getElementById('dashboard-page');

        const t0 = performance.now();
        await handleServiceCaseEvent(pageRoot, {
            incident_id: 201,
            queue: 'action_required',
            list_actions: { action_required: 'add' },
            html: '<tr id="service-case-row-201"><td>SC00201</td></tr>',
        }, 'ServiceCaseCreated');
        await handleServiceCaseEvent(pageRoot, {
            incident_id: 10,
            queue: 'action_required',
            list_actions: { action_required: 'remove' },
        }, 'SlaStatusChanged');
        const classicMs = performance.now() - t0;

        await vi.advanceTimersByTimeAsync(500);
        const reconcileAfterProven = refreshSpy.mock.calls.length;

        pageRoot.dataset.liveQueue = 'waiting_customer';
        await handleServiceCaseEvent(pageRoot, {
            incident_id: 202,
            queue: 'action_required',
            list_actions: {
                waiting_customer: 'ignore',
                action_required: 'add',
            },
            html: '<tr id="service-case-row-202"><td>hidden</td></tr>',
        }, 'ServiceCaseCreated');

        await vi.advanceTimersByTimeAsync(500);
        const reconcileAfterUnsafe = refreshSpy.mock.calls.length;

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                rows: [],
                remove_incident_ids: [201],
            }),
        }));
        pageRoot.dataset.liveQueue = 'action_required';
        // Row 201 still present from ADD above.
        const t1 = performance.now();
        await handleHybridIncidentsUpdated(pageRoot, { incident_ids: [201] });
        const hybridMs = performance.now() - t1;
        await vi.advanceTimersByTimeAsync(500);

        // eslint-disable-next-line no-console
        console.log(JSON.stringify({
            classic_event_handling_ms: Number(classicMs.toFixed(3)),
            hybrid_remove_handling_ms: Number(hybridMs.toFixed(3)),
            kpis_only_calls_after_proven_add_remove: reconcileAfterProven,
            kpis_only_calls_after_unsafe_other_queue: reconcileAfterUnsafe,
            ready_count: document.querySelector('[data-dashboard-case-filter-count="action_required"]')?.textContent,
        }));

        expect(reconcileAfterProven).toBe(0);
        expect(reconcileAfterUnsafe).toBe(1);
        // 10 → +1 (201) → -1 (10) → -1 hybrid (201) = 9; unsafe other-queue create did not ±1.
        expect(document.querySelector('[data-dashboard-case-filter-count="action_required"]')?.textContent)
            .toBe('(9)');
    });
});
