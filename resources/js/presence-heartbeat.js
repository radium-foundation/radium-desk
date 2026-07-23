import { csrfToken } from './workspace/http';
import { logRefreshLifecycle } from './dashboard-refresh-lifecycle';

const parseInterval = (value, fallback = 120) => {
    const parsed = Number.parseInt(String(value ?? ''), 10);

    return Number.isFinite(parsed) && parsed >= 30 ? parsed : fallback;
};

export const initPresenceHeartbeat = () => {
    if (!configEnabled()) {
        return;
    }

    const root = document.body;
    const url = root.dataset.presenceHeartbeatUrl;

    if (root.dataset.presenceHeartbeat !== 'true' || !url) {
        return;
    }

    const intervalMs = parseInterval(root.dataset.presenceHeartbeatInterval, 120) * 1000;
    let lastInteractionPingAt = 0;
    let timerId = null;

    const sendHeartbeat = async () => {
        if (document.visibilityState !== 'visible') {
            return;
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ source: 'heartbeat' }),
            });

            if (response.status === 401) {
                window.location.assign('/login');

                return;
            }

            if (!response.ok) {
                logRefreshLifecycle(document.getElementById('dashboard-page'), 'presence_heartbeat_response_ignored', {
                    status: response.status,
                    source: 'presence-heartbeat',
                });

                return;
            }

            const data = await response.json();
            const nextInterval = parseInterval(data.next_heartbeat_seconds, 120) * 1000;

            if (timerId !== null) {
                window.clearInterval(timerId);
            }

            timerId = window.setInterval(sendHeartbeat, nextInterval);
        } catch (error) {
            logRefreshLifecycle(document.getElementById('dashboard-page'), 'presence_heartbeat_request_failed', {
                source: 'presence-heartbeat',
                errorMessage: error?.message ?? String(error),
            });
            // Ignore transient network failures.
        }
    };

    const maybePingFromInteraction = () => {
        const now = Date.now();

        if (now - lastInteractionPingAt < 60000) {
            return;
        }

        lastInteractionPingAt = now;
        sendHeartbeat();
    };

    ['mousemove', 'keydown', 'click', 'scroll'].forEach((eventName) => {
        document.addEventListener(eventName, maybePingFromInteraction, { passive: true });
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            sendHeartbeat();
        }
    });

    sendHeartbeat();
    timerId = window.setInterval(sendHeartbeat, intervalMs);
};

const configEnabled = () => {
    const root = document.body;

    return root.dataset.presenceHeartbeatEnabled !== 'false';
};
