import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    configureDashboardPolling,
    currentPollingMode,
    destroyPolling,
    isPollingActive,
    POLL_MODE_FAST,
    POLL_MODE_LEGACY,
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

    it('heartbeat mode is suppressed while Ably is the primary transport', async () => {
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

        expect(currentPollingMode()).toBe(POLL_MODE_LEGACY);
        expect(isPollingActive()).toBe(false);

        await vi.advanceTimersByTimeAsync(5 * 60_000);

        expect(refreshDashboard).not.toHaveBeenCalled();
    });

    it('heartbeat mode does not schedule polls when the tab is hidden', async () => {
        const pageRoot = document.getElementById('dashboard-page');
        const refreshDashboard = vi.fn().mockResolvedValue(undefined);
        const previousVisibility = document.visibilityState;

        configureDashboardPolling({
            refreshDashboard,
            getWorkspaceSession: () => ({
                isActive: () => false,
                onIdle: vi.fn(),
            }),
        });

        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            get: () => 'hidden',
        });

        startHeartbeatPolling(pageRoot);

        await vi.advanceTimersByTimeAsync(5 * 60_000);

        expect(refreshDashboard).not.toHaveBeenCalled();
        expect(isPollingActive()).toBe(false);

        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            get: () => previousVisibility,
        });
    });

    it('heartbeat mode does not poll after prolonged user inactivity', async () => {
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

        await vi.advanceTimersByTimeAsync(10 * 60_000);

        expect(refreshDashboard).not.toHaveBeenCalled();
        expect(isPollingActive()).toBe(false);
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

    it('does not leave a heartbeat timer running after startHeartbeatPolling', async () => {
        const pageRoot = document.getElementById('dashboard-page');

        startHeartbeatPolling(pageRoot);
        startFastPolling(pageRoot);

        expect(isPollingActive()).toBe(true);
        expect(currentPollingMode()).toBe(POLL_MODE_FAST);

        stopPolling();
        expect(isPollingActive()).toBe(false);
    });
});
