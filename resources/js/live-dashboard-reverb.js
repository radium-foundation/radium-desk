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
import { isDashboardSearchActive } from './dashboard-search-mode';
import { isDashboardQuickFilterActive } from './dashboard-service-case-state';
import { getWorkspaceSession } from './workspace/session';
import { maybeHandleIncomingCallInteraction } from './incoming-call-interaction';

const SERVICE_CASE_EVENTS = [
    'ServiceCaseCreated',
    'TransactionAssigned',
    'ServiceCaseRemarked',
    'ServiceCaseResolved',
    'ServiceCaseClosed',
    'SlaStatusChanged',
];

const shouldRemoveRowForFilter = (pageRoot, payload) => {
    if (!payload.remove_from_list) {
        return false;
    }

    const queue = pageRoot.dataset.liveQueue ?? pageRoot.dataset.liveFilter ?? 'action_required';

    return queue === 'action_required'
        || queue === 'attention'
        || queue === 'pending_admin'
        || queue === 'overdue'
        || queue === 'warning';
};

const handleServiceCaseEvent = async (pageRoot, payload) => {
    if (isDashboardSearchActive() || isDashboardQuickFilterActive()) {
        return;
    }

    const lockedIncidentIds = getWorkspaceSession().getLockedIncidentIds();
    const incidentId = Number(payload.incident_id);

    if (shouldRemoveRowForFilter(pageRoot, payload)) {
        await applyPartialDashboardUpdate({
            remove_incident_ids: lockedIncidentIds.includes(incidentId) ? [] : [incidentId],
        });

        return;
    }

    if (!payload.html) {
        return;
    }

    await applyPartialDashboardUpdate({
        rows: [{
            incident_id: incidentId,
            html: payload.html,
        }],
    });
};

const handleKpisUpdated = async (payload) => {
    if (isDashboardSearchActive() || isDashboardQuickFilterActive()) {
        return;
    }

    await applyPartialDashboardUpdate({
        kpi_strip_html: payload.kpi_strip_html,
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

    if (payload.title && payload.message) {
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

    dashboardChannel.listen('.DashboardKpisUpdated', (payload) => {
        handleKpisUpdated(payload);
    });

    notificationsChannel.listen('.NotificationCreated', (payload) => {
        handleNotificationCreated(payload);
    });

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
    handleKpisUpdated,
    handleNotificationCreated,
    handleServiceCaseEvent,
    shouldRemoveRowForFilter,
};
