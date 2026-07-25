import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    initPlatformDashboard,
    startPlatformPolling,
    stopPlatformPolling,
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
