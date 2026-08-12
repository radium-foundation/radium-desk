import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    cancelReadyQueueRowReconcile,
    maybeScheduleReadyQueueRowReconcileAfterCounts,
    resetReadyQueueRowReconcileForTests,
    scheduleReadyQueueRowReconcile,
} from '../../resources/js/ready-queue-row-reconcile';

vi.mock('../../resources/js/live-dashboard', () => ({
    refreshDashboard: vi.fn(async () => ({})),
}));

import { refreshDashboard } from '../../resources/js/live-dashboard';

const buildReadyQueuePage = () => {
    document.body.innerHTML = `
        <div id="dashboard-page"
             data-live-url="/dashboard/live"
             data-live-queue="action_required"
             data-live-filter="action_required"
             data-live-workspace="action_required">
            <div class="dashboard-service-cases-card">
                <span data-dashboard-case-filter-count="action_required">(10)</span>
            </div>
            <table><tbody id="dashboard-service-cases-body">
                <tr id="service-case-row-10"><td>SC00010</td></tr>
            </tbody></table>
        </div>
    `;

    return document.getElementById('dashboard-page');
};

describe('ready-queue-row-reconcile', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        resetReadyQueueRowReconcileForTests();
        vi.mocked(refreshDashboard).mockClear();
    });

    afterEach(() => {
        resetReadyQueueRowReconcileForTests();
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    it('debounces row catch-up refresh for the active Ready Queue', async () => {
        const pageRoot = buildReadyQueuePage();

        scheduleReadyQueueRowReconcile(pageRoot);
        scheduleReadyQueueRowReconcile(pageRoot);
        scheduleReadyQueueRowReconcile(pageRoot);

        expect(refreshDashboard).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(500);

        expect(refreshDashboard).toHaveBeenCalledTimes(1);
        expect(refreshDashboard).toHaveBeenCalledWith(
            pageRoot,
            'ready-queue-row-reconcile',
        );
        expect(String(refreshDashboard.mock.calls[0][1])).not.toContain('kpisOnly');
    });

    it('does not schedule when not viewing Ready Queue', async () => {
        const pageRoot = buildReadyQueuePage();
        pageRoot.dataset.liveQueue = 'scheduled';
        pageRoot.dataset.liveFilter = 'scheduled';

        scheduleReadyQueueRowReconcile(pageRoot);

        await vi.advanceTimersByTimeAsync(500);

        expect(refreshDashboard).not.toHaveBeenCalled();
    });

    it('maybeScheduleReadyQueueRowReconcileAfterCounts schedules when counts change without rows', async () => {
        const pageRoot = buildReadyQueuePage();

        maybeScheduleReadyQueueRowReconcileAfterCounts(pageRoot, {
            service_case_filter_counts: {
                action_required: 11,
            },
        });

        await vi.advanceTimersByTimeAsync(500);

        expect(refreshDashboard).toHaveBeenCalledWith(
            pageRoot,
            'ready-queue-row-reconcile',
        );
    });

    it('maybeScheduleReadyQueueRowReconcileAfterCounts skips when rows were mutated', async () => {
        const pageRoot = buildReadyQueuePage();

        maybeScheduleReadyQueueRowReconcileAfterCounts(pageRoot, {
            service_case_filter_counts: {
                action_required: 11,
            },
        }, { hadRowMutation: true });

        await vi.advanceTimersByTimeAsync(500);

        expect(refreshDashboard).not.toHaveBeenCalled();
    });

    it('maybeScheduleReadyQueueRowReconcileAfterCounts skips shortly after a row mutation', async () => {
        const pageRoot = buildReadyQueuePage();
        const { notifyReadyQueueRowMutated } = await import('../../resources/js/ready-queue-row-reconcile');

        notifyReadyQueueRowMutated();

        maybeScheduleReadyQueueRowReconcileAfterCounts(pageRoot, {
            service_case_filter_counts: {
                action_required: 11,
            },
        });

        await vi.advanceTimersByTimeAsync(500);

        expect(refreshDashboard).not.toHaveBeenCalled();
    });

    it('cancelReadyQueueRowReconcile prevents a pending catch-up', async () => {
        const pageRoot = buildReadyQueuePage();

        scheduleReadyQueueRowReconcile(pageRoot);
        cancelReadyQueueRowReconcile();

        await vi.advanceTimersByTimeAsync(500);

        expect(refreshDashboard).not.toHaveBeenCalled();
    });
});
