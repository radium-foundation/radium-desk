/**
 * Single-flight timeout poller that pauses while the document is hidden
 * and catch-up ticks when the tab becomes visible again.
 */
export const createVisibilityAwarePoller = ({
    getIntervalMs,
    tick,
    shouldRun = () => true,
    runImmediately = false,
} = {}) => {
    let timeoutId = null;
    let destroyed = false;
    let inFlight = false;

    const clear = () => {
        if (timeoutId === null) {
            return;
        }

        window.clearTimeout(timeoutId);
        timeoutId = null;
    };

    const runTick = async () => {
        if (destroyed || document.hidden || inFlight || !shouldRun()) {
            return;
        }

        inFlight = true;

        try {
            await tick();
        } finally {
            inFlight = false;
        }
    };

    const schedule = () => {
        clear();

        if (destroyed || document.hidden || !shouldRun()) {
            return;
        }

        const intervalMs = Number(getIntervalMs?.() ?? 0);

        if (!Number.isFinite(intervalMs) || intervalMs <= 0) {
            return;
        }

        timeoutId = window.setTimeout(async () => {
            timeoutId = null;

            if (destroyed || document.hidden || !shouldRun()) {
                return;
            }

            await runTick();
            schedule();
        }, intervalMs);
    };

    const onVisibilityChange = () => {
        if (destroyed) {
            return;
        }

        if (document.hidden) {
            clear();

            return;
        }

        void (async () => {
            await runTick();
            schedule();
        })();
    };

    document.addEventListener('visibilitychange', onVisibilityChange);

    if (runImmediately && !document.hidden && shouldRun()) {
        void (async () => {
            await runTick();
            schedule();
        })();
    } else {
        schedule();
    }

    return {
        restart: () => {
            if (destroyed) {
                return;
            }

            schedule();
        },
        stop: () => {
            destroyed = true;
            clear();
            document.removeEventListener('visibilitychange', onVisibilityChange);
        },
    };
};
