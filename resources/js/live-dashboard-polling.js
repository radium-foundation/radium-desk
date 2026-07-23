/**
 * Dashboard HTTP polling modes used alongside Reverb.
 *
 * - fast fallback: aggressive refresh when the WebSocket is down or recovering from staleness
 * - heartbeat: low-frequency safety net while Reverb stays connected
 */

import { logRefreshLifecycle } from './dashboard-refresh-lifecycle';

export const POLL_MODE_LEGACY = 'legacy';
export const POLL_MODE_FAST = 'fast';
export const POLL_MODE_HEARTBEAT = 'heartbeat';

const DEFAULT_HEARTBEAT_MS = 60_000;
const DEFAULT_HEARTBEAT_SLOW_MS = 5 * 60_000;
const DEFAULT_USER_IDLE_MS = 5 * 60_000;

let pollTimeoutId = null;
let pollingActive = false;
let pollPageRoot = null;
let pollMode = POLL_MODE_LEGACY;
let pollIntervalMs = 30_000;
let pollIntervalActiveMs = 30_000;
let pollIntervalIdleMs = 60_000;
let pollIntervalOverrideMs = null;
let lastUserActivityAt = Date.now();
let heartbeatListenersBound = false;
let pollVisibilityHandler = null;
let pollWorkspaceReleaseHandler = null;
let refreshDashboardFn = null;
let getWorkspaceSessionFn = null;

const USER_ACTIVITY_EVENTS = ['mousedown', 'keydown', 'touchstart', 'scroll'];

export const configureDashboardPolling = ({ refreshDashboard, getWorkspaceSession }) => {
    refreshDashboardFn = refreshDashboard;
    getWorkspaceSessionFn = getWorkspaceSession;
};

const recordUserActivity = () => {
    lastUserActivityAt = Date.now();
};

const readHeartbeatActiveMs = (pageRoot) => {
    const explicit = Number(pageRoot?.dataset.liveHeartbeatMs ?? 0);

    if (explicit > 0) {
        return explicit;
    }

    const idleSettingMs = Number(pageRoot?.dataset.liveIntervalIdle ?? 0);

    return idleSettingMs > 0 ? idleSettingMs : DEFAULT_HEARTBEAT_MS;
};

const readHeartbeatSlowMs = (pageRoot) => {
    const explicit = Number(pageRoot?.dataset.liveHeartbeatSlowMs ?? 0);

    return explicit > 0 ? explicit : DEFAULT_HEARTBEAT_SLOW_MS;
};

const readUserIdleThresholdMs = (pageRoot) => {
    const explicit = Number(pageRoot?.dataset.liveUserIdleMs ?? 0);

    return explicit > 0 ? explicit : DEFAULT_USER_IDLE_MS;
};

const isLegacyPollingIdle = (pageRoot) => {
    if (document.visibilityState === 'hidden') {
        return true;
    }

    if (typeof getWorkspaceSessionFn === 'function' && getWorkspaceSessionFn().isActive()) {
        return true;
    }

    return false;
};

/**
 * Heartbeat mode: visible tab only; slows down after prolonged user inactivity.
 * Returns null when the heartbeat should pause (hidden tab).
 */
const resolveHeartbeatIntervalMs = (pageRoot) => {
    if (document.visibilityState === 'hidden') {
        return null;
    }

    const idleThresholdMs = readUserIdleThresholdMs(pageRoot);
    const inactiveForMs = Date.now() - lastUserActivityAt;

    if (inactiveForMs >= idleThresholdMs) {
        return readHeartbeatSlowMs(pageRoot);
    }

    return readHeartbeatActiveMs(pageRoot);
};

const resolvePollIntervalMs = (pageRoot) => {
    if (pollIntervalOverrideMs !== null) {
        return pollIntervalOverrideMs;
    }

    if (pollMode === POLL_MODE_HEARTBEAT) {
        return resolveHeartbeatIntervalMs(pageRoot);
    }

    if (pollMode === POLL_MODE_FAST) {
        return Number(pageRoot?.dataset.liveIntervalActive ?? pageRoot?.dataset.liveInterval ?? pollIntervalActiveMs);
    }

    const activeMs = Number(pageRoot?.dataset.liveIntervalActive ?? pageRoot?.dataset.liveInterval ?? pollIntervalActiveMs);
    const idleMs = Number(pageRoot?.dataset.liveIntervalIdle ?? pollIntervalIdleMs);

    return isLegacyPollingIdle(pageRoot) ? idleMs : activeMs;
};

const bindHeartbeatListeners = () => {
    if (heartbeatListenersBound) {
        return;
    }

    heartbeatListenersBound = true;
    recordUserActivity();

    USER_ACTIVITY_EVENTS.forEach((eventName) => {
        document.addEventListener(eventName, recordUserActivity, { passive: true });
    });
};

const bindPollingIntervalListeners = (pageRoot) => {
    if (pollVisibilityHandler !== null) {
        return;
    }

    pollVisibilityHandler = () => {
        reschedulePollingInterval(pageRoot);
    };

    pollWorkspaceReleaseHandler = () => {
        reschedulePollingInterval(pageRoot);
    };

    document.addEventListener('visibilitychange', pollVisibilityHandler);

    if (pollMode === POLL_MODE_LEGACY && typeof getWorkspaceSessionFn === 'function') {
        getWorkspaceSessionFn().onIdle(pollWorkspaceReleaseHandler);
    }
};

const reschedulePollingInterval = (pageRoot) => {
    if (! pollingActive || pollPageRoot === null) {
        return;
    }

    const nextIntervalMs = resolvePollIntervalMs(pageRoot);

    logRefreshLifecycle(pageRoot, 'poll_reschedule_evaluated', {
        pollMode,
        nextIntervalMs,
        currentIntervalMs: pollIntervalMs,
        hasTimeout: pollTimeoutId !== null,
    });

    if (nextIntervalMs === null) {
        if (pollTimeoutId !== null) {
            window.clearTimeout(pollTimeoutId);
            pollTimeoutId = null;
        }

        return;
    }

    if (nextIntervalMs === pollIntervalMs && pollTimeoutId !== null) {
        return;
    }

    pollIntervalMs = nextIntervalMs;

    if (pollTimeoutId !== null) {
        window.clearTimeout(pollTimeoutId);
        pollTimeoutId = null;
    }

    scheduleNextPoll(pageRoot);
};

const scheduleNextPoll = (pageRoot) => {
    if (! pollingActive || pollPageRoot === null || pollTimeoutId !== null) {
        logRefreshLifecycle(pageRoot, 'poll_schedule_suppressed', {
            pollingActive,
            hasPollPageRoot: pollPageRoot !== null,
            pollTimeoutId,
            pollMode,
        });

        return;
    }

    pollIntervalMs = resolvePollIntervalMs(pageRoot);

    if (pollIntervalMs === null) {
        logRefreshLifecycle(pageRoot, 'poll_schedule_suppressed', {
            reason: 'interval_null',
            pollMode,
            documentHidden: document.visibilityState === 'hidden',
        });

        return;
    }

    logRefreshLifecycle(pageRoot, 'poll_scheduled', {
        pollMode,
        pollIntervalMs,
    });

    pollTimeoutId = window.setTimeout(async () => {
        pollTimeoutId = null;

        const activePageRoot = pollPageRoot;

        logRefreshLifecycle(activePageRoot, 'poll_timer_fired', {
            pollMode,
            pollingActive,
        });

        if (! pollingActive || activePageRoot === null) {
            logRefreshLifecycle(activePageRoot, 'poll_timer_suppressed', {
                reason: 'polling_inactive_or_missing_page_root',
            });

            return;
        }

        if (typeof refreshDashboardFn === 'function') {
            await refreshDashboardFn(activePageRoot, `poll_${pollMode}`);
        } else {
            logRefreshLifecycle(activePageRoot, 'poll_timer_suppressed', {
                reason: 'missing_refresh_dashboard_fn',
            });
        }

        if (pollingActive && pollPageRoot === activePageRoot) {
            scheduleNextPoll(activePageRoot);
        } else {
            logRefreshLifecycle(activePageRoot, 'poll_reschedule_suppressed', {
                pollingActive,
                samePageRoot: pollPageRoot === activePageRoot,
            });
        }
    }, pollIntervalMs);
};

const startPollingWithMode = (pageRoot, mode, intervalMs = null) => {
    if (! pageRoot || typeof refreshDashboardFn !== 'function') {
        logRefreshLifecycle(pageRoot, 'poll_start_suppressed', {
            reason: ! pageRoot ? 'missing_page_root' : 'missing_refresh_dashboard_fn',
            mode,
        });

        return;
    }

    logRefreshLifecycle(pageRoot, 'poll_starting', {
        mode,
        intervalMs,
    });

    stopPolling();

    pollIntervalActiveMs = Number(pageRoot.dataset.liveIntervalActive ?? pageRoot.dataset.liveInterval ?? 30_000);
    pollIntervalIdleMs = Number(pageRoot.dataset.liveIntervalIdle ?? 60_000);
    pollMode = mode;
    pollIntervalOverrideMs = intervalMs;
    pollingActive = true;
    pollPageRoot = pageRoot;
    pollIntervalMs = intervalMs ?? resolvePollIntervalMs(pageRoot);

    if (mode === POLL_MODE_HEARTBEAT) {
        bindHeartbeatListeners();
    }

    bindPollingIntervalListeners(pageRoot);
    scheduleNextPoll(pageRoot);

    logRefreshLifecycle(pageRoot, 'poll_started', {
        mode,
        pollIntervalMs,
    });
};

/** Legacy poll-only transport (no Reverb). Uses active/idle intervals from system settings. */
export const startPolling = (pageRoot, intervalMs = null) => {
    startPollingWithMode(pageRoot, POLL_MODE_LEGACY, intervalMs);
};

/** Fast fallback mode: used when Reverb disconnects or while recovering from a stale socket. */
export const startFastPolling = (pageRoot, intervalMs = null) => {
    startPollingWithMode(pageRoot, POLL_MODE_FAST, intervalMs);
};

/** Heartbeat mode: low-frequency refresh while Reverb remains the primary transport. */
export const startHeartbeatPolling = (pageRoot, intervalMs = null) => {
    if (pageRoot?.dataset.liveUpdatesEnabled === '0') {
        return;
    }

    startPollingWithMode(pageRoot, POLL_MODE_HEARTBEAT, intervalMs);
};

export const stopPolling = () => {
    const pageRoot = pollPageRoot;

    if (pageRoot) {
        logRefreshLifecycle(pageRoot, 'poll_stopping', {
            pollMode,
            hadTimeout: pollTimeoutId !== null,
        });
    }

    pollingActive = false;
    pollPageRoot = null;
    pollMode = POLL_MODE_LEGACY;
    pollIntervalOverrideMs = null;

    if (pollTimeoutId === null) {
        return;
    }

    window.clearTimeout(pollTimeoutId);
    pollTimeoutId = null;
};

export const destroyPolling = () => {
    stopPolling();

    if (pollVisibilityHandler !== null) {
        document.removeEventListener('visibilitychange', pollVisibilityHandler);
        pollVisibilityHandler = null;
    }

    pollWorkspaceReleaseHandler = null;
};

export const isPollingActive = () => pollingActive;

export const currentPollingMode = () => pollMode;
