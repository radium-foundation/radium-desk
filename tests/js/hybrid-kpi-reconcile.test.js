import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    cancelHybridKpiReconcile,
    resetHybridKpiReconcileForTests,
    scheduleHybridKpiReconcile,
} from '../../resources/js/hybrid-kpi-reconcile';

const refreshDashboard = vi.hoisted(() => vi.fn().mockResolvedValue(undefined));

vi.mock('../../resources/js/live-dashboard', () => ({
    refreshDashboard,
}));

describe('hybrid KPI reconcile scheduler', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        resetHybridKpiReconcileForTests();
        refreshDashboard.mockClear();
        document.body.innerHTML = `
            <div id="dashboard-page" data-live-url="/dashboard/live"></div>
        `;
    });

    afterEach(() => {
        resetHybridKpiReconcileForTests();
        vi.useRealTimers();
        vi.clearAllMocks();
    });

    it('schedules a debounced KPI-only refresh after hybrid row merge', async () => {
        const pageRoot = document.getElementById('dashboard-page');

        scheduleHybridKpiReconcile(pageRoot);

        expect(refreshDashboard).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(500);

        expect(refreshDashboard).toHaveBeenCalledTimes(1);
        expect(refreshDashboard).toHaveBeenCalledWith(
            pageRoot,
            'hybrid-kpi-reconcile',
            { kpisOnly: true },
        );
        // live-dashboard maps kpisOnly → query kpis_only=1 (server counts-only short-circuit).
    });

    it('coalesces multiple schedules into one reconcile', async () => {
        const pageRoot = document.getElementById('dashboard-page');

        scheduleHybridKpiReconcile(pageRoot);
        await vi.advanceTimersByTimeAsync(200);
        scheduleHybridKpiReconcile(pageRoot);
        await vi.advanceTimersByTimeAsync(200);
        scheduleHybridKpiReconcile(pageRoot);

        expect(refreshDashboard).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(500);

        expect(refreshDashboard).toHaveBeenCalledTimes(1);
    });

    it('cancels a pending reconcile before it fires', async () => {
        const pageRoot = document.getElementById('dashboard-page');

        scheduleHybridKpiReconcile(pageRoot);
        cancelHybridKpiReconcile();

        await vi.advanceTimersByTimeAsync(1000);

        expect(refreshDashboard).not.toHaveBeenCalled();
    });
});
