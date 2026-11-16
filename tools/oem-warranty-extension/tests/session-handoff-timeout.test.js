import { describe, expect, it, vi } from 'vitest';
import {
    createHandoffTimeoutRegistry,
    OEM_SESSION_HANDOFF_TIMEOUT_MS,
    simulateSessionHandoffTimeoutPhases,
} from '../src/lib/session-handoff-timeout.js';

describe('session-handoff-timeout', () => {
    it('fires timeout when session never becomes ready', () => {
        vi.useFakeTimers();
        const trigger = {};
        const onTimeout = vi.fn();
        const registry = createHandoffTimeoutRegistry(12_000);

        registry.arm(trigger, onTimeout);
        vi.advanceTimersByTime(11_999);
        expect(onTimeout).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        expect(onTimeout).toHaveBeenCalledTimes(1);

        registry.clear(trigger);
        vi.useRealTimers();
    });

    it('re-arms after session ready so a slow POST does not consume the extension budget', () => {
        const withoutRearm = simulateSessionHandoffTimeoutPhases({
            timeoutMs: 12_000,
            events: [
                { atMs: 0, type: 'click' },
                { atMs: 11_000, type: 'session_ready' },
                { atMs: 13_000, type: 'extension_ack' },
            ],
        }, { rearmOnSessionReady: false });

        expect(withoutRearm.timedOut).toBe(true);
        expect(withoutRearm.completedAtMs).toBe(null);

        const withRearm = simulateSessionHandoffTimeoutPhases({
            timeoutMs: 12_000,
            events: [
                { atMs: 0, type: 'click' },
                { atMs: 11_000, type: 'session_ready' },
                { atMs: 13_000, type: 'extension_ack' },
            ],
        }, { rearmOnSessionReady: true });

        // Re-arm on session_ready is modeled inside simulate by arm() on that event type.
        expect(withRearm.timedOut).toBe(false);
        expect(withRearm.completedAtMs).toBe(13_000);
    });

    it('still times out when extension ack is slower than the per-phase budget', () => {
        const result = simulateSessionHandoffTimeoutPhases({
            timeoutMs: OEM_SESSION_HANDOFF_TIMEOUT_MS,
            events: [
                { atMs: 0, type: 'click' },
                { atMs: 1_000, type: 'session_ready' },
                { atMs: 13_500, type: 'extension_ack' },
            ],
        });

        expect(result.timedOut).toBe(true);
        expect(result.timeoutAtMs).toBe(13_000);
        expect(result.completedAtMs).toBe(null);
    });
});