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

    const mountPlatformPage = () => {
        document.body.innerHTML = `
            <div id="platform-dashboard-root" data-poll-interval-seconds="1">
                <div data-platform-card-slot="platform_health">
                    <article
                        data-platform-card
                        data-refresh-url="/admin/platform/cards/platform_health"
                    >
                        <button type="button" data-platform-card-refresh>
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                        <div>Platform Health</div>
                    </article>
                </div>
            </div>
        `;

        return document.getElementById('platform-dashboard-root');
    };

    beforeEach(() => {
        vi.useFakeTimers();
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                html: `
                    <article data-platform-card data-refresh-url="/admin/platform/cards/platform_health">
                        <div>Refreshed Platform Health</div>
                    </article>
                `,
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

    it('auto-refreshes refreshable cards on the configured interval', async () => {
        mountPlatformPage();
        initPlatformDashboard();

        expect(fetch).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1000);

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(fetch).toHaveBeenCalledWith(
            '/admin/platform/cards/platform_health',
            expect.objectContaining({
                credentials: 'same-origin',
            }),
        );
    });

    it('pauses polling while the browser tab is hidden', async () => {
        const root = mountPlatformPage();
        startPlatformPolling(root, 1000);

        fetch.mockClear();

        setVisibilityState('hidden');
        document.dispatchEvent(new Event('visibilitychange'));

        await vi.advanceTimersByTimeAsync(5000);

        expect(fetch).not.toHaveBeenCalled();
    });

    it('refreshes cards when the tab becomes visible again', async () => {
        const root = mountPlatformPage();
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

    it('priority-refreshes zones after first paint', async () => {
        document.body.innerHTML = `
            <div id="platform-dashboard-root" data-platform-zones data-zone-concurrency="2" data-poll-interval-seconds="0">
                <section data-platform-zone="integration_health" data-platform-zone-priority="2" data-refresh-url="/admin/platform/zones/integration_health">
                    <div data-platform-zone-body>pending</div>
                    <button type="button" data-platform-zone-refresh><i></i></button>
                </section>
                <section data-platform-zone="platform_health" data-platform-zone-priority="1" data-refresh-url="/admin/platform/zones/platform_health">
                    <div data-platform-zone-body>pending</div>
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
                }),
            };
        }));

        const root = document.getElementById('platform-dashboard-root');
        await refreshZonesByPriority(root);

        expect(calls[0]).toContain('/admin/platform/zones/platform_health');
        expect(calls[1]).toContain('/admin/platform/zones/integration_health');
        expect(root.querySelector('[data-platform-zone="platform_health"] [data-platform-zone-body]').innerHTML)
            .toContain('refreshed:');
    });
});
