import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    cancelHybridKpiReconcile,
    resetHybridKpiReconcileForTests,
    scheduleHybridKpiReconcile,
} from '../../resources/js/hybrid-kpi-reconcile';

const reconcileViewOnlyMetrics = vi.hoisted(() => vi.fn().mockResolvedValue(undefined));

vi.mock('../../resources/js/dashboard-live-counts', () => ({
    reconcileViewOnlyMetrics,
}));

describe('hybrid KPI reconcile scheduler', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        resetHybridKpiReconcileForTests();
        reconcileViewOnlyMetrics.mockClear();
        document.body.innerHTML = `
            <div id="dashboard-page" data-live-counts-url="/dashboard/live/counts"></div>
        `;
    });

    afterEach(() => {
        resetHybridKpiReconcileForTests();
        vi.useRealTimers();
        vi.clearAllMocks();
    });

    it('schedules a debounced view-only metric reconcile after hybrid row merge', async () => {
        const pageRoot = document.getElementById('dashboard-page');

        scheduleHybridKpiReconcile(pageRoot);

        expect(reconcileViewOnlyMetrics).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(500);

        expect(reconcileViewOnlyMetrics).toHaveBeenCalledTimes(1);
        expect(reconcileViewOnlyMetrics).toHaveBeenCalledWith(pageRoot);
    });

    it('coalesces multiple schedules into one reconcile', async () => {
        const pageRoot = document.getElementById('dashboard-page');

        scheduleHybridKpiReconcile(pageRoot);
        await vi.advanceTimersByTimeAsync(200);
        scheduleHybridKpiReconcile(pageRoot);
        await vi.advanceTimersByTimeAsync(200);
        scheduleHybridKpiReconcile(pageRoot);

        expect(reconcileViewOnlyMetrics).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(500);

        expect(reconcileViewOnlyMetrics).toHaveBeenCalledTimes(1);
    });

    it('cancels a pending reconcile before it fires', async () => {
        const pageRoot = document.getElementById('dashboard-page');

        scheduleHybridKpiReconcile(pageRoot);
        cancelHybridKpiReconcile();

        await vi.advanceTimersByTimeAsync(1000);

        expect(reconcileViewOnlyMetrics).not.toHaveBeenCalled();
    });
});
