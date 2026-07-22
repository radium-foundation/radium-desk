import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import {
    animateNotificationBell,
    bindNotificationDropdownSession,
    replaceNotificationBell,
    showBrowserNotification,
    updateUnreadBadge,
} from './live-notifications';
import {
    applyKpis,
    applyPartialDashboardUpdate,
    configureLiveDashboard,
} from './live-dashboard';
import { buildDashboardLiveQuery } from './dashboard-live-query';
import { isDashboardSearchActive } from './dashboard-search-mode';
import { isDashboardQuickFilterActive } from './dashboard-service-case-state';
import { getWorkspaceSession } from './workspace/session';
import { maybeHandleIncomingCallInteraction } from './incoming-call-interaction';
import { bindOperatorAlertsChannel } from './operator-alerts';
import { bindRealtimeNotificationsChannel } from './realtime-notifications';

const SERVICE_CASE_EVENTS = [
    'ServiceCaseCreated',
    'TransactionAssigned',
    'ServiceCaseRemarked',
    'ServiceCaseResolved',
    'ServiceCaseClosed',
    'SlaStatusChanged',
];

const resolveListAction = (pageRoot, payload) => {
    const activeQueue = pageRoot.dataset.liveQueue ?? pageRoot.dataset.liveFilter ?? 'action_required';

    return payload.list_actions?.[activeQueue] ?? 'ignore';
};

const normalizeIncidentIds = (payload) => {
    const rawIds = Array.isArray(payload?.incident_ids)
        ? payload.incident_ids
        : (payload?.incident_id !== undefined ? [payload.incident_id] : []);

    return rawIds
        .map((id) => Number(id))
        .filter((id) => Number.isFinite(id) && id > 0);
};

const fetchLiveRowsForIncidents = async (pageRoot, incidentIds) => {
    const rowsUrl = pageRoot.dataset.liveRowsUrl;

    if (!rowsUrl || incidentIds.length === 0) {
        return {
            rows: [],
            remove_incident_ids: [],
        };
    }

    const query = buildDashboardLiveQuery(pageRoot);
    incidentIds.forEach((id) => {
        query.append('ids[]', String(id));
    });

    const response = await fetch(`${rowsUrl}?${query.toString()}`, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        return {
            rows: [],
            remove_incident_ids: [],
        };
    }

    const data = await response.json();

    return {
        rows: Array.isArray(data.rows) ? data.rows : [],
        remove_incident_ids: Array.isArray(data.remove_incident_ids) ? data.remove_incident_ids : [],
    };
};

const HYBRID_INCIDENT_EVENTS = [
    'ReferenceNumbersUpdated',
    'ServiceCasesAssigned',
    'ServiceCasesResolved',
    'ServiceCasesClosed',
];

const handleHybridIncidentsUpdated = async (pageRoot, payload) => {
    if (isDashboardSearchActive() || isDashboardQuickFilterActive()) {
        return;
    }

    const incidentIds = normalizeIncidentIds(payload);

    if (incidentIds.length === 0) {
        return;
    }

    const lockedIncidentIds = getWorkspaceSession().getLockedIncidentIds();
    const fetchIds = incidentIds.filter((id) => !lockedIncidentIds.includes(id));

    if (fetchIds.length === 0) {
        return;
    }

    const { rows, remove_incident_ids: removeIncidentIds } = await fetchLiveRowsForIncidents(
        pageRoot,
        fetchIds,
    );

    if (rows.length === 0 && removeIncidentIds.length === 0) {
        return;
    }

    await applyPartialDashboardUpdate({
        rows,
        remove_incident_ids: removeIncidentIds.filter((id) => !lockedIncidentIds.includes(Number(id))),
    });
};

const handleReferenceNumbersUpdated = handleHybridIncidentsUpdated;

const handleServiceCaseEvent = async (pageRoot, payload) => {
    if (isDashboardSearchActive() || isDashboardQuickFilterActive()) {
        return;
    }

    const action = resolveListAction(pageRoot, payload);

    if (action === 'ignore') {
        return;
    }

    const lockedIncidentIds = getWorkspaceSession().getLockedIncidentIds();
    const incidentId = Number(payload.incident_id);

    if (action === 'remove') {
        await applyPartialDashboardUpdate({
            remove_incident_ids: lockedIncidentIds.includes(incidentId) ? [] : [incidentId],
        });

        return;
    }

    if ((action === 'add' || action === 'update') && payload.html) {
        await applyPartialDashboardUpdate({
            rows: [{
                incident_id: incidentId,
                html: payload.html,
            }],
        });
    }
};

const handleKpisUpdated = async (payload) => {
    if (isDashboardSearchActive() || isDashboardQuickFilterActive()) {
        return;
    }

    const pageRoot = document.getElementById('dashboard-page');
    const liveScope = pageRoot?.dataset.liveScope ?? 'operations_scope';
    const filterCounts = payload.service_case_filter_count_variants?.[liveScope];

    await applyPartialDashboardUpdate({
        kpi_strip_html: payload.kpi_strip_html,
        service_case_filter_counts: filterCounts,
    });
};

const handleNotificationCreated = (pageRoot, payload) => {
    const root = document.getElementById('notification-bell-root');

    if (!root) {
        maybeHandleIncomingCallInteraction(payload.interaction);

        return;
    }

    if (payload.bell_html) {
        animateNotificationBell();

        const unreadCount = Number(payload.unread_count ?? 0);

        if (getWorkspaceSession().isActive('notification-dropdown')) {
            updateUnreadBadge(root, unreadCount);
        } else {
            replaceNotificationBell(payload.bell_html);
        }
    }

    const desktopNotificationsEnabled = pageRoot?.dataset.realtimeDesktopNotifications !== '0';

    if (desktopNotificationsEnabled && payload.title && payload.message && !payload.suppress_desktop_notification) {
        showBrowserNotification({
            id: payload.id,
            title: payload.title,
            message: payload.message,
            url: payload.url,
        });
    }

    maybeHandleIncomingCallInteraction(payload.interaction);
};

const REALTIME_LOG_EVENTS = new Set([
    'provider_change',
    'connection_established',
    'disconnect',
    'fallback_activated',
    'reconnect_success',
]);

let activeRealtimeSession = null;

const reportRealtimeConnectionStatus = async (pageRoot, status, message = null, { force = false } = {}) => {
    const statusUrl = pageRoot?.dataset.realtimeStatusUrl;

    if (!statusUrl) {
        return;
    }

    const now = Date.now();
    const throttleMs = 5000;
    const lastReportAt = Number(pageRoot.dataset.realtimeLastStatusReportAt ?? 0);

    if (! force && status !== 'connected' && status !== 'error' && now - lastReportAt < throttleMs) {
        return;
    }

    pageRoot.dataset.realtimeLastStatusReportAt = String(now);

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    try {
        await fetch(statusUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                status,
                provider: pageRoot.dataset.realtimeProvider ?? 'polling',
                message,
            }),
        });
    } catch (error) {
        // Ignore transient status reporting failures.
    }
};

const logRealtime = (pageRoot, event, detail = null) => {
    if (! REALTIME_LOG_EVENTS.has(event)) {
        return;
    }

    debugRealtime(pageRoot, event, detail);
};

const debugRealtime = (pageRoot, message, detail = null) => {
    if (pageRoot?.dataset.realtimeDebug !== '1') {
        return;
    }

    if (detail !== null) {
        console.debug(`[realtime] ${message}`, detail);

        return;
    }

    console.debug(`[realtime] ${message}`);
};

const formatTooltip = (pageRoot, metadata) => {
    const provider = pageRoot?.dataset.realtimeProvider ?? 'polling';
    const lastConnected = metadata.lastConnectedAt ?? 'Never';
    const lastDisconnect = metadata.lastDisconnectReason ?? 'None';

    return `Provider: ${provider}\nLast connected: ${lastConnected}\nLast disconnect: ${lastDisconnect}`;
};

const ensureConnectionIndicator = (pageRoot) => {
    if (pageRoot?.dataset.realtimeConnectionIndicator !== '1') {
        return null;
    }

    let indicator = document.getElementById('dashboard-realtime-connection-indicator');

    if (indicator) {
        return indicator;
    }

    indicator = document.createElement('div');
    indicator.id = 'dashboard-realtime-connection-indicator';
    indicator.className = 'dashboard-realtime-connection-indicator badge text-bg-secondary position-fixed';
    indicator.style.cssText = 'bottom: 1rem; right: 1rem; z-index: 1040; opacity: 0.9; cursor: default;';
    indicator.setAttribute('aria-live', 'polite');
    document.body.appendChild(indicator);

    return indicator;
};

const updateConnectionIndicator = (pageRoot, status, metadata = {}) => {
    const indicator = ensureConnectionIndicator(pageRoot);

    if (!indicator) {
        return;
    }

    const labels = {
        connected: '● Connected',
        connecting: '● Connecting',
        polling: '● Polling',
        offline: '● Offline',
    };

    indicator.textContent = labels[status] ?? '● Disconnected';
    indicator.title = formatTooltip(pageRoot, metadata);
    indicator.classList.remove('text-bg-success', 'text-bg-secondary', 'text-bg-danger', 'text-bg-warning', 'text-bg-info');

    if (status === 'connected') {
        indicator.classList.add('text-bg-success');
    } else if (status === 'connecting') {
        indicator.classList.add('text-bg-info');
    } else if (status === 'polling') {
        indicator.classList.add('text-bg-warning');
    } else if (status === 'offline') {
        indicator.classList.add('text-bg-secondary');
    } else {
        indicator.classList.add('text-bg-danger');
    }
};

const createEchoInstance = (pageRoot) => {
    window.Pusher = Pusher;

    const broadcaster = pageRoot.dataset.echoBroadcaster;
    const scheme = pageRoot.dataset.echoScheme ?? 'https';
    const host = pageRoot.dataset.echoHost ?? window.location.hostname;
    const port = Number(pageRoot.dataset.echoPort ?? (scheme === 'https' ? 443 : 80));
    const key = pageRoot.dataset.echoKey;

    if (!key || !broadcaster) {
        return null;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    return new Echo({
        broadcaster,
        key,
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        encrypted: scheme === 'https',
        disableStats: true,
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-CSRF-TOKEN': csrfToken ?? '',
                Accept: 'application/json',
            },
        },
    });
};

const bindConnectionHandlers = ({
    pageRoot,
    connection,
    metadata,
    startPolling,
    stopPolling,
    fallbackPoll,
    dashboardLiveUpdates,
    getConnected,
    setConnected,
}) => {
    const handlers = [];

    const bind = (event, handler) => {
        connection.bind(event, handler);
        handlers.push({ event, handler });
    };

    const activatePollingFallback = (reason) => {
        if (! fallbackPoll || ! dashboardLiveUpdates || ! startPolling) {
            return;
        }

        startPolling();
        metadata.lastDisconnectReason = reason;
        updateConnectionIndicator(pageRoot, 'polling', metadata);
        reportRealtimeConnectionStatus(pageRoot, 'polling', `fallback_activated: ${reason}`);
        logRealtime(pageRoot, 'fallback_activated', { reason });
    };

    bind('connected', () => {
        const wasConnected = getConnected();
        setConnected(true);
        metadata.lastConnectedAt = new Date().toLocaleString();
        metadata.lastDisconnectReason = null;
        updateConnectionIndicator(pageRoot, 'connected', metadata);
        reportRealtimeConnectionStatus(pageRoot, 'connected', null, { force: true });
        logRealtime(pageRoot, wasConnected ? 'reconnect_success' : 'connection_established');
        stopPolling?.();
    });

    bind('disconnected', () => {
        setConnected(false);
        const reason = 'WebSocket disconnected';
        metadata.lastDisconnectReason = reason;
        updateConnectionIndicator(pageRoot, 'polling', metadata);
        reportRealtimeConnectionStatus(pageRoot, 'disconnected', reason, { force: true });
        logRealtime(pageRoot, 'disconnect', { reason });
        activatePollingFallback(reason);
    });

    bind('error', (error) => {
        const message = error?.error?.data?.message ?? error?.error?.message ?? 'Connection error';
        setConnected(false);
        metadata.lastDisconnectReason = message;
        updateConnectionIndicator(pageRoot, 'offline', metadata);
        reportRealtimeConnectionStatus(pageRoot, 'error', message, { force: true });
        logRealtime(pageRoot, 'disconnect', { reason: message });

        if (! getConnected()) {
            activatePollingFallback(message);
        }
    });

    bind('state_change', (states) => {
        if (states.current === 'connecting') {
            updateConnectionIndicator(pageRoot, 'connecting', metadata);
        }
    });

    return handlers;
};

const unbindConnectionHandlers = (connection, handlers) => {
    handlers.forEach(({ event, handler }) => {
        connection.unbind(event, handler);
    });
};

const destroyEchoInstance = (echo) => {
    if (! echo) {
        return;
    }

    try {
        echo.connector?.pusher?.connection?.disconnect();
        echo.disconnect();
    } catch (error) {
        // Ignore teardown errors during navigation.
    }
};

export const destroyLiveDashboardRealtime = () => {
    if (! activeRealtimeSession) {
        return;
    }

    activeRealtimeSession.destroy();
    activeRealtimeSession = null;
};

export const forceLiveDashboardRealtimeReconnect = () => {
    activeRealtimeSession?.forceReconnect();
};

export const initLiveDashboardReverb = ({
    pageRoot,
    startPolling,
    stopPolling,
    destroyPolling,
    hooks = {},
    fallbackPoll = true,
    dashboardLiveUpdates = true,
} = {}) => {
    destroyLiveDashboardRealtime();

    if (!pageRoot?.dataset.echoKey) {
        if (fallbackPoll && dashboardLiveUpdates && startPolling) {
            startPolling();
            updateConnectionIndicator(pageRoot, 'polling', {});
            reportRealtimeConnectionStatus(pageRoot, 'polling', 'fallback_activated: echo_unavailable');
        }

        return { connected: false, destroy: destroyLiveDashboardRealtime };
    }

    configureLiveDashboard(hooks);

    const metadata = {
        lastConnectedAt: null,
        lastDisconnectReason: null,
    };

    let echo = createEchoInstance(pageRoot);
    const userId = pageRoot.dataset.userId;

    if (!echo || !userId) {
        if (fallbackPoll && dashboardLiveUpdates && startPolling) {
            startPolling();
            updateConnectionIndicator(pageRoot, 'polling', metadata);
        }

        return { connected: false, destroy: destroyLiveDashboardRealtime };
    }

    let reverbConnected = false;
    let connectionHandlers = [];
    let onlineHandler = null;
    let offlineHandler = null;
    let beforeUnloadHandler = null;
    let destroyed = false;

    const dashboardChannel = echo.private(`dashboard.${userId}`);
    const notificationsChannel = echo.private(`notifications.${userId}`);

    if (dashboardLiveUpdates) {
        SERVICE_CASE_EVENTS.forEach((eventName) => {
            dashboardChannel.listen(`.${eventName}`, (payload) => {
                handleServiceCaseEvent(pageRoot, payload);
            });
        });

        HYBRID_INCIDENT_EVENTS.forEach((eventName) => {
            dashboardChannel.listen(`.${eventName}`, (payload) => {
                handleHybridIncidentsUpdated(pageRoot, payload);
            });
        });

        dashboardChannel.listen('.DashboardKpisUpdated', (payload) => {
            handleKpisUpdated(payload);
        });
    }

    notificationsChannel.listen('.NotificationCreated', (payload) => {
        handleNotificationCreated(pageRoot, payload);
    });

    bindOperatorAlertsChannel(notificationsChannel);
    bindRealtimeNotificationsChannel(notificationsChannel);

    const connection = echo.connector?.pusher?.connection;

    const teardown = () => {
        if (destroyed) {
            return;
        }

        destroyed = true;
        stopPolling?.();

        if (onlineHandler) {
            window.removeEventListener('online', onlineHandler);
        }

        if (offlineHandler) {
            window.removeEventListener('offline', offlineHandler);
        }

        if (beforeUnloadHandler) {
            window.removeEventListener('beforeunload', beforeUnloadHandler);
        }

        if (connection && connectionHandlers.length > 0) {
            unbindConnectionHandlers(connection, connectionHandlers);
            connectionHandlers = [];
        }

        destroyEchoInstance(echo);
        echo = null;
        destroyPolling?.();
    };

    const setupConnection = () => {
        if (! echo || destroyed) {
            return;
        }

        const liveConnection = echo.connector?.pusher?.connection;

        if (! liveConnection) {
            return;
        }

        if (connectionHandlers.length > 0) {
            unbindConnectionHandlers(liveConnection, connectionHandlers);
        }

        connectionHandlers = bindConnectionHandlers({
            pageRoot,
            connection: liveConnection,
            metadata,
            startPolling,
            stopPolling,
            fallbackPoll,
            dashboardLiveUpdates,
            getConnected: () => reverbConnected,
            setConnected: (value) => {
                reverbConnected = value;
            },
        });

        updateConnectionIndicator(pageRoot, 'connecting', metadata);
        reportRealtimeConnectionStatus(pageRoot, 'connecting');
    };

    const forceReconnect = () => {
        if (destroyed || ! echo) {
            return;
        }

        reverbConnected = false;
        const liveConnection = echo.connector?.pusher?.connection;

        if (! liveConnection) {
            return;
        }

        updateConnectionIndicator(pageRoot, 'connecting', metadata);
        reportRealtimeConnectionStatus(pageRoot, 'connecting', null, { force: true });
        liveConnection.disconnect();
        liveConnection.connect();
    };

    if (connection) {
        setupConnection();

        onlineHandler = () => {
            if (destroyed || ! navigator.onLine) {
                return;
            }

            const liveConnection = echo?.connector?.pusher?.connection;

            if (liveConnection && liveConnection.state !== 'connected') {
                updateConnectionIndicator(pageRoot, 'connecting', metadata);
                liveConnection.connect();
            }
        };

        offlineHandler = () => {
            metadata.lastDisconnectReason = 'Browser offline';
            updateConnectionIndicator(pageRoot, 'offline', metadata);
            reportRealtimeConnectionStatus(pageRoot, 'offline', 'Browser offline', { force: true });
            logRealtime(pageRoot, 'disconnect', { reason: 'Browser offline' });
        };

        window.addEventListener('online', onlineHandler);
        window.addEventListener('offline', offlineHandler);
    }

    beforeUnloadHandler = () => {
        teardown();
        activeRealtimeSession = null;
    };

    window.addEventListener('beforeunload', beforeUnloadHandler);

    if (pageRoot.dataset.realtimeForceReconnectAt) {
        forceReconnect();
    }

    bindNotificationDropdownSession(document.getElementById('notification-bell-root'));

    const session = {
        destroy: teardown,
        forceReconnect,
        echo: () => echo,
        isReverbConnected: () => reverbConnected,
    };

    activeRealtimeSession = session;

    return {
        connected: true,
        echo,
        destroy: teardown,
        forceReconnect,
        isReverbConnected: () => reverbConnected,
    };
};

export {
    createEchoInstance,
    destroyLiveDashboardRealtime,
    forceLiveDashboardRealtimeReconnect,
    fetchLiveRowsForIncidents,
    handleHybridIncidentsUpdated,
    handleKpisUpdated,
    handleNotificationCreated,
    handleReferenceNumbersUpdated,
    handleServiceCaseEvent,
    normalizeIncidentIds,
    resolveListAction,
};
