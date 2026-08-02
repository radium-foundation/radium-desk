import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    initPlatformDashboard,
    startPlatformPolling,
    stopPlatformPolling,
    refreshZonesByPriority,
    runWithConcurrency,
} from '../../resources/js/platform-dashboard';

describe('platform-dashboard polling', () => {
    const setVisibilityState = (state) => {
        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            get: () => state,
        });
        Object.defineProperty(document, 'hidden', {
            configurable: true,
            get: () => state === 'hidden',
        });
    };

    const mountZonePlatformPage = ({ available = 'false', stale = 'false' } = {}) => {
        document.body.innerHTML = `
            <div id="platform-dashboard-root" data-platform-zones data-poll-interval-seconds="1" data-zone-concurrency="2">
                <section
                    data-platform-zone="platform_health"
                    data-platform-zone-priority="1"
                    data-zone-available="${available}"
                    data-zone-stale="${stale}"
                    data-refresh-url="/admin/platform/zones/platform_health"
                >
                    <div data-platform-zone-body>body</div>
                    <button type="button" data-platform-zone-refresh><i></i></button>
                </section>
            </div>
        `;

        return document.getElementById('platform-dashboard-root');
    };

    beforeEach(() => {
        vi.useFakeTimers();
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                html: '<div>refreshed</div>',
                status: 'healthy',
                status_label: 'Healthy',
                available: true,
                stale: false,
            }),
        }));
        setVisibilityState('visible');
    });

    afterEach(() => {
        stopPlatformPolling();
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        vi.useRealTimers();
        setVisibilityState('visible');
    });

    it('auto-refreshes stale priority zones on the configured interval', async () => {
        mountZonePlatformPage({ available: 'true', stale: 'true' });
        initPlatformDashboard();

        fetch.mockClear();

        await vi.advanceTimersByTimeAsync(1000);

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(fetch).toHaveBeenCalledWith(
            '/admin/platform/zones/platform_health',
            expect.objectContaining({
                credentials: 'same-origin',
            }),
        );
    });

    it('does not poll-refresh fresh zones', async () => {
        mountZonePlatformPage({ available: 'true', stale: 'false' });
        initPlatformDashboard();

        fetch.mockClear();

        await vi.advanceTimersByTimeAsync(3000);

        expect(fetch).not.toHaveBeenCalled();
    });

    it('pauses polling while the browser tab is hidden', async () => {
        const root = mountZonePlatformPage({ available: 'true', stale: 'true' });
        startPlatformPolling(root, 1000);

        fetch.mockClear();

        setVisibilityState('hidden');
        document.dispatchEvent(new Event('visibilitychange'));

        await vi.advanceTimersByTimeAsync(5000);

        expect(fetch).not.toHaveBeenCalled();
    });

    it('intelligently refreshes stale zones when the tab becomes visible again', async () => {
        const root = mountZonePlatformPage({ available: 'true', stale: 'true' });
        startPlatformPolling(root, 1000);

        fetch.mockClear();

        setVisibilityState('hidden');
        document.dispatchEvent(new Event('visibilitychange'));

        setVisibilityState('visible');
        document.dispatchEvent(new Event('visibilitychange'));

        await vi.waitFor(() => {
            expect(fetch).toHaveBeenCalled();
        });
    });
});

describe('platform zone scheduler', () => {
    const setVisibilityState = (state) => {
        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            get: () => state,
        });
        Object.defineProperty(document, 'hidden', {
            configurable: true,
            get: () => state === 'hidden',
        });
    };

    beforeEach(() => {
        vi.stubGlobal('localStorage', {
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
        setVisibilityState('visible');
    });

    afterEach(() => {
        stopPlatformPolling();
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        setVisibilityState('visible');
    });

    it('refreshes zones by priority with limited concurrency', async () => {
        const started = [];
        const order = [];

        await runWithConcurrency(
            ['a', 'b', 'c', 'd'],
            2,
            async (item) => {
                started.push(item);
                order.push(`start:${item}`);
                await Promise.resolve();
                order.push(`end:${item}`);
            },
        );

        expect(started).toEqual(['a', 'b', 'c', 'd']);
        expect(order[0]).toBe('start:a');
        expect(order[1]).toBe('start:b');
    });

    it('priority-refreshes only stale or unavailable priority zones', async () => {
        document.body.innerHTML = `
            <div id="platform-dashboard-root" data-platform-zones data-zone-concurrency="2" data-poll-interval-seconds="0">
                <section data-platform-zone="performance" data-platform-zone-priority="3" data-zone-available="false" data-zone-stale="false" data-refresh-url="/admin/platform/zones/performance">
                    <div data-platform-zone-body>pending</div>
                    <button type="button" data-platform-zone-refresh><i></i></button>
                </section>
                <section data-platform-zone="integration_health" data-platform-zone-priority="2" data-zone-available="false" data-zone-stale="false" data-refresh-url="/admin/platform/zones/integration_health">
                    <div data-platform-zone-body>pending</div>
                    <button type="button" data-platform-zone-refresh><i></i></button>
                </section>
                <section data-platform-zone="platform_health" data-platform-zone-priority="1" data-zone-available="false" data-zone-stale="false" data-refresh-url="/admin/platform/zones/platform_health">
                    <div data-platform-zone-body>pending</div>
                    <button type="button" data-platform-zone-refresh><i></i></button>
                </section>
                <section data-platform-zone="tools" data-platform-zone-priority="5" data-zone-available="true" data-zone-stale="false" data-refresh-url="/admin/platform/zones/tools">
                    <div data-platform-zone-body>ready</div>
                    <button type="button" data-platform-zone-refresh><i></i></button>
                </section>
            </div>
        `;

        const calls = [];
        vi.stubGlobal('fetch', vi.fn(async (url) => {
            calls.push(url);

            return {
                ok: true,
                json: async () => ({
                    html: `<div>refreshed:${url}</div>`,
                    status: 'healthy',
                    status_label: 'Healthy',
                    updated_at: '2026-08-02T12:00:00+05:30',
                    available: true,
                    stale: false,
                }),
            };
        }));

        const root = document.getElementById('platform-dashboard-root');
        await refreshZonesByPriority(root);

        expect(calls[0]).toContain('/admin/platform/zones/platform_health');
        expect(calls[1]).toContain('/admin/platform/zones/integration_health');
        expect(calls).toHaveLength(2);
        expect(calls.join(',')).not.toContain('/performance');
        expect(calls.join(',')).not.toContain('/tools');
    });

    it('skips auto-refresh for fresh snapshots', async () => {
        document.body.innerHTML = `
            <div id="platform-dashboard-root" data-platform-zones data-poll-interval-seconds="0">
                <section data-platform-zone="platform_health" data-platform-zone-priority="1" data-zone-available="true" data-zone-stale="false" data-refresh-url="/admin/platform/zones/platform_health">
                    <div data-platform-zone-body>fresh</div>
                    <button type="button" data-platform-zone-refresh><i></i></button>
                </section>
            </div>
        `;

        vi.stubGlobal('fetch', vi.fn());

        await refreshZonesByPriority(document.getElementById('platform-dashboard-root'));

        expect(fetch).not.toHaveBeenCalled();
    });

    it('forceAll refreshes every zone including fresh ones', async () => {
        document.body.innerHTML = `
            <div id="platform-dashboard-root" data-platform-zones data-poll-interval-seconds="0">
                <section data-platform-zone="platform_health" data-platform-zone-priority="1" data-zone-available="true" data-zone-stale="false" data-refresh-url="/admin/platform/zones/platform_health">
                    <div data-platform-zone-body>fresh</div>
                    <button type="button" data-platform-zone-refresh><i></i></button>
                </section>
            </div>
        `;

        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            json: async () => ({ html: '<div>forced</div>', status: 'healthy', status_label: 'Healthy', available: true, stale: false }),
        })));

        await refreshZonesByPriority(document.getElementById('platform-dashboard-root'), { forceAll: true });

        expect(fetch).toHaveBeenCalledTimes(1);
    });
});
