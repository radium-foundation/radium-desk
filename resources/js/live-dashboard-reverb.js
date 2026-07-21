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

const handleNotificationCreated = (payload) => {
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

    if (payload.title && payload.message && !payload.suppress_desktop_notification) {
        showBrowserNotification({
            id: payload.id,
            title: payload.title,
            message: payload.message,
            url: payload.url,
        });
    }

    maybeHandleIncomingCallInteraction(payload.interaction);
};

const createEchoInstance = (pageRoot) => {
    window.Pusher = Pusher;

    const scheme = pageRoot.dataset.reverbScheme ?? 'http';
    const host = pageRoot.dataset.reverbHost ?? window.location.hostname;
    const port = Number(pageRoot.dataset.reverbPort ?? (scheme === 'https' ? 443 : 80));
    const key = pageRoot.dataset.reverbKey;

    if (!key) {
        return null;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    return new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
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

export const initLiveDashboardReverb = ({
    pageRoot,
    startPolling,
    stopPolling,
    hooks = {},
    fallbackPoll = true,
} = {}) => {
    if (!pageRoot?.dataset.reverbKey) {
        if (fallbackPoll && startPolling) {
            startPolling();
        }

        return { connected: false };
    }

    configureLiveDashboard(hooks);

    const echo = createEchoInstance(pageRoot);
    const userId = pageRoot.dataset.userId;

    if (!echo || !userId) {
        if (fallbackPoll && startPolling) {
            startPolling();
        }

        return { connected: false };
    }

    let reverbConnected = false;
    const dashboardChannel = echo.private(`dashboard.${userId}`);
    const notificationsChannel = echo.private(`notifications.${userId}`);

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

    notificationsChannel.listen('.NotificationCreated', (payload) => {
        handleNotificationCreated(payload);
    });

    bindOperatorAlertsChannel(notificationsChannel);
    bindRealtimeNotificationsChannel(notificationsChannel);

    const connection = echo.connector?.pusher?.connection;

    if (connection) {
        connection.bind('connected', () => {
            reverbConnected = true;
            stopPolling?.();
        });

        connection.bind('disconnected', () => {
            reverbConnected = false;

            if (fallbackPoll && startPolling) {
                startPolling();
            }
        });

        connection.bind('error', () => {
            if (!reverbConnected && fallbackPoll && startPolling) {
                startPolling();
            }
        });
    }

    bindNotificationDropdownSession(document.getElementById('notification-bell-root'));

    return {
        connected: true,
        echo,
        isReverbConnected: () => reverbConnected,
    };
};

export {
    createEchoInstance,
    fetchLiveRowsForIncidents,
    handleHybridIncidentsUpdated,
    handleKpisUpdated,
    handleNotificationCreated,
    handleReferenceNumbersUpdated,
    handleServiceCaseEvent,
    normalizeIncidentIds,
    resolveListAction,
};
