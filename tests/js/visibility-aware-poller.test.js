import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createVisibilityAwarePoller } from '../../resources/js/polling/visibility-aware-poller';

describe('createVisibilityAwarePoller', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        Object.defineProperty(document, 'hidden', {
            configurable: true,
            get: () => false,
        });
    });

    afterEach(() => {
        vi.useRealTimers();
        Object.defineProperty(document, 'hidden', {
            configurable: true,
            get: () => false,
        });
    });

    it('ticks on the configured interval while visible', async () => {
        const tick = vi.fn().mockResolvedValue(undefined);
        const poller = createVisibilityAwarePoller({
            getIntervalMs: () => 1000,
            tick,
        });

        await vi.advanceTimersByTimeAsync(1000);
        expect(tick).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(1000);
        expect(tick).toHaveBeenCalledTimes(2);

        poller.stop();
    });

    it('pauses while hidden and catch-up ticks when visible again', async () => {
        let hidden = false;
        Object.defineProperty(document, 'hidden', {
            configurable: true,
            get: () => hidden,
        });

        const tick = vi.fn().mockResolvedValue(undefined);
        const poller = createVisibilityAwarePoller({
            getIntervalMs: () => 1000,
            tick,
            runImmediately: true,
        });

        await Promise.resolve();
        expect(tick).toHaveBeenCalledTimes(1);

        hidden = true;
        document.dispatchEvent(new Event('visibilitychange'));
        await vi.advanceTimersByTimeAsync(5000);
        expect(tick).toHaveBeenCalledTimes(1);

        hidden = false;
        document.dispatchEvent(new Event('visibilitychange'));
        await Promise.resolve();
        expect(tick).toHaveBeenCalledTimes(2);

        poller.stop();
    });
});
