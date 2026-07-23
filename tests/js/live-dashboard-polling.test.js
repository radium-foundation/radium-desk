import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    configureDashboardPolling,
    currentPollingMode,
    destroyPolling,
    isPollingActive,
    POLL_MODE_FAST,
    POLL_MODE_HEARTBEAT,
    startFastPolling,
    startHeartbeatPolling,
    startPolling,
    stopPolling,
} from '../../resources/js/live-dashboard-polling';

describe('live dashboard polling modes', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML = `
            <div id="dashboard-page"
                 data-live-url="/dashboard/live"
                 data-live-updates-enabled="1"
                 data-live-interval-active="20000"
                 data-live-interval-idle="60000"></div>
        `;

        configureDashboardPolling({
            refreshDashboard: vi.fn().mockResolvedValue(undefined),
            getWorkspaceSession: () => ({
                isActive: () => false,
                onIdle: vi.fn(),
            }),
        });
    });

    afterEach(() => {
        destroyPolling();
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('uses fast fallback interval from active system setting', async () => {
        const pageRoot = document.getElementById('dashboard-page');
        const refreshDashboard = vi.fn().mockResolvedValue(undefined);

        configureDashboardPolling({
            refreshDashboard,
            getWorkspaceSession: () => ({
                isActive: () => false,
                onIdle: vi.fn(),
            }),
        });

        startFastPolling(pageRoot);

        expect(currentPollingMode()).toBe(POLL_MODE_FAST);
        expect(isPollingActive()).toBe(true);

        await vi.advanceTimersByTimeAsync(20_000);

        expect(refreshDashboard).toHaveBeenCalledTimes(1);
    });

    it('heartbeat mode polls every 60 seconds while visible and active', async () => {
        const pageRoot = document.getElementById('dashboard-page');
        const refreshDashboard = vi.fn().mockResolvedValue(undefined);

        configureDashboardPolling({
            refreshDashboard,
            getWorkspaceSession: () => ({
                isActive: () => false,
                onIdle: vi.fn(),
            }),
        });

        startHeartbeatPolling(pageRoot);

        expect(currentPollingMode()).toBe(POLL_MODE_HEARTBEAT);

        await vi.advanceTimersByTimeAsync(59_999);
        expect(refreshDashboard).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        expect(refreshDashboard).toHaveBeenCalledTimes(1);
    });

    it('pauses heartbeat polling while the browser tab is hidden', async () => {
        const pageRoot = document.getElementById('dashboard-page');
        const refreshDashboard = vi.fn().mockResolvedValue(undefined);

        configureDashboardPolling({
            refreshDashboard,
            getWorkspaceSession: () => ({
                isActive: () => false,
                onIdle: vi.fn(),
            }),
        });

        startHeartbeatPolling(pageRoot);

        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            get: () => 'hidden',
        });
        document.dispatchEvent(new Event('visibilitychange'));

        await vi.advanceTimersByTimeAsync(120_000);
        expect(refreshDashboard).not.toHaveBeenCalled();

        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            get: () => 'visible',
        });
        document.dispatchEvent(new Event('visibilitychange'));

        await vi.advanceTimersByTimeAsync(60_000);
        expect(refreshDashboard).toHaveBeenCalledTimes(1);
    });

    it('slows heartbeat polling after five minutes of user inactivity', async () => {
        const pageRoot = document.getElementById('dashboard-page');
        const refreshDashboard = vi.fn().mockResolvedValue(undefined);

        configureDashboardPolling({
            refreshDashboard,
            getWorkspaceSession: () => ({
                isActive: () => false,
                onIdle: vi.fn(),
            }),
        });

        startHeartbeatPolling(pageRoot);

        await vi.advanceTimersByTimeAsync(5 * 60_000);

        refreshDashboard.mockClear();

        await vi.advanceTimersByTimeAsync(5 * 60_000 - 1);
        expect(refreshDashboard).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        expect(refreshDashboard).toHaveBeenCalledTimes(1);
    });

    it('legacy poll-only mode still uses active and idle intervals', async () => {
        const pageRoot = document.getElementById('dashboard-page');
        const refreshDashboard = vi.fn().mockResolvedValue(undefined);

        configureDashboardPolling({
            refreshDashboard,
            getWorkspaceSession: () => ({
                isActive: () => false,
                onIdle: vi.fn(),
            }),
        });

        startPolling(pageRoot);

        await vi.advanceTimersByTimeAsync(20_000);
        expect(refreshDashboard).toHaveBeenCalledTimes(1);
    });

    it('stopPolling clears the active mode', () => {
        const pageRoot = document.getElementById('dashboard-page');

        startFastPolling(pageRoot);
        expect(isPollingActive()).toBe(true);

        stopPolling();
        expect(isPollingActive()).toBe(false);
    });
});
