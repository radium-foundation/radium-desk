export const OEM_SESSION_HANDOFF_TIMEOUT_MS = 12_000;

/**
 * Per-trigger watchdog for session-mode OEM handoff. Each phase gets a full timeout
 * budget so POST + extension ack are not competing for one deadline.
 */
export function createHandoffTimeoutRegistry(timeoutMs = OEM_SESSION_HANDOFF_TIMEOUT_MS) {
    /** @type {WeakMap<object, number>} */
    const timeoutIds = new WeakMap();

    const clear = (trigger) => {
        const id = timeoutIds.get(trigger);
        if (id != null) {
            clearTimeout(id);
            timeoutIds.delete(trigger);
        }
    };

    const arm = (trigger, onTimeout) => {
        clear(trigger);
        const id = setTimeout(onTimeout, timeoutMs);
        timeoutIds.set(trigger, id);
        return id;
    };

    return { clear, arm };
}

/**
 * Simulates session handoff timeout phases for unit tests.
 *
 * @param {{ timeoutMs: number, events: Array<{ atMs: number, type: 'click'|'session_pending'|'session_ready'|'extension_ack' }> }} input
 * @param {{ rearmOnSessionReady?: boolean }} [options]
 * @returns {{ timedOut: boolean, timeoutAtMs: number|null, completedAtMs: number|null }}
 */
export function simulateSessionHandoffTimeoutPhases(input, options = {}) {
    const { rearmOnSessionReady = true } = options;
    const { timeoutMs, events } = input;
    let activeDeadline = null;
    let timedOut = false;
    let timeoutAtMs = null;
    let completedAtMs = null;

    const arm = (atMs) => {
        activeDeadline = atMs + timeoutMs;
    };

    for (const event of [...events].sort((a, b) => a.atMs - b.atMs)) {
        if (completedAtMs != null || timedOut) {
            break;
        }

        if (activeDeadline != null && event.atMs > activeDeadline) {
            timedOut = true;
            timeoutAtMs = activeDeadline;
            break;
        }

        if (event.type === 'click' || event.type === 'session_pending') {
            arm(event.atMs);
            continue;
        }

        if (event.type === 'session_ready') {
            if (rearmOnSessionReady) {
                arm(event.atMs);
            }
            continue;
        }

        if (event.type === 'extension_ack') {
            activeDeadline = null;
            completedAtMs = event.atMs;
        }
    }

    return { timedOut, timeoutAtMs, completedAtMs };
}