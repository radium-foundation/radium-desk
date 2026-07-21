import * as bootstrap from 'bootstrap';
import {
    animateNotificationBell,
    replaceNotificationBell,
    showBrowserNotification,
    updateUnreadBadge,
} from './live-notifications';
import { getWorkspaceSession } from './workspace/session';
import { maybeHandleIncomingCallInteraction } from './incoming-call-interaction';
import { showIncomingCallCard, updateIncomingCallCard } from './incoming-call-card';

const shownKeys = new Set();
const criticalToasts = new Map();

const PRIORITY_VARIANTS = {
    critical: 'danger',
    high: 'warning',
    normal: 'primary',
    silent: 'secondary',
};

const ensureToastContainer = () => {
    let container = document.querySelector('.toast-container');

    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = '1090';
        document.body.appendChild(container);
    }

    return container;
};

export const playNotificationSound = () => {
    try {
        const audio = new Audio('/sounds/operator-alert.mp3');
        audio.volume = 0.5;
        void audio.play();
    } catch {
        // Ignore missing audio assets or autoplay restrictions.
    }
};

export const showRealtimeToast = (payload) => {
    if (!payload?.show_toast) {
        return;
    }

    const priority = payload.priority ?? 'normal';
    const variant = PRIORITY_VARIANTS[priority] ?? 'primary';
    const container = ensureToastContainer();
    const toastElement = document.createElement('div');
    toastElement.className = `toast align-items-center text-bg-${variant} border-0 app-toast realtime-notification-toast`;
    toastElement.dataset.notificationId = payload.id ?? '';
    toastElement.setAttribute('role', 'alert');
    toastElement.setAttribute('aria-live', 'assertive');
    toastElement.setAttribute('aria-atomic', 'true');

    const body = document.createElement('div');
    body.className = 'toast-body app-toast-body';

    const titleNode = document.createElement('div');
    titleNode.className = 'fw-semibold';
    titleNode.textContent = payload.title ?? 'Notification';
    body.appendChild(titleNode);

    if (payload.message) {
        const messageNode = document.createElement('div');
        messageNode.className = 'small mt-1';
        messageNode.textContent = payload.message;
        body.appendChild(messageNode);
    }

    const actions = Array.isArray(payload.actions) ? payload.actions : [];

    if (payload.action_url) {
        actions.unshift({ label: 'Open', url: payload.action_url });
    }

    if (actions.length > 0) {
        const actionsWrap = document.createElement('div');
        actionsWrap.className = 'app-toast-actions mt-2';

        actions.forEach((action) => {
            const actionButton = document.createElement('button');
            actionButton.type = 'button';
            actionButton.className = 'app-toast-action';
            actionButton.textContent = action.label ?? 'Open';
            actionButton.addEventListener('click', () => {
                if (action.url) {
                    window.location.href = action.url;
                }

                bootstrap.Toast.getOrCreateInstance(toastElement)?.hide();
            });
            actionsWrap.appendChild(actionButton);
        });

        body.appendChild(actionsWrap);
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'd-flex';
    wrapper.appendChild(body);

    if (!payload.requires_acknowledgement) {
        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'btn-close btn-close-white me-2 m-auto';
        closeButton.setAttribute('data-bs-dismiss', 'toast');
        closeButton.setAttribute('aria-label', 'Dismiss');
        wrapper.appendChild(closeButton);
    }

    toastElement.appendChild(wrapper);
    container.appendChild(toastElement);

    const autohide = !payload.requires_acknowledgement;
    const delay = Number(payload.toast_duration_ms ?? (actions.length > 0 ? 6500 : 5000));

    const toast = bootstrap.Toast.getOrCreateInstance(toastElement, {
        autohide,
        delay,
    });

    toastElement.addEventListener('hidden.bs.toast', () => {
        toastElement.remove();
        criticalToasts.delete(payload.id);
    });

    if (payload.requires_acknowledgement && payload.id) {
        criticalToasts.set(payload.id, toast);
    }

    toast.show();
};

const shouldDedupe = (key) => {
    if (!key || shownKeys.has(key)) {
        return true;
    }

    shownKeys.add(key);

    return false;
};

const updateNotificationBell = (payload) => {
    const root = document.getElementById('notification-bell-root');

    if (!root || !payload.bell_html) {
        return;
    }

    animateNotificationBell();

    const unreadCount = Number(payload.unread_count ?? 0);

    if (getWorkspaceSession().isActive('notification-dropdown')) {
        updateUnreadBadge(root, unreadCount);
    } else {
        replaceNotificationBell(payload.bell_html);
    }
};

export const handleRealtimeNotificationDelivered = (payload) => {
    if (!payload) {
        return;
    }

    const dedupeKey = payload.deduplication_key ?? payload.id;

    if (shouldDedupe(dedupeKey)) {
        return;
    }

    updateNotificationBell(payload);

    if (payload.browser_notification && payload.title) {
        showBrowserNotification({
            id: payload.id,
            title: payload.title,
            message: payload.message,
            url: payload.action_url,
        });
    }

    showRealtimeToast(payload);

    if (payload.play_sound) {
        playNotificationSound();
    }

    maybeHandleIncomingCallInteraction(payload.interaction);
};

export const handleIncomingCallReceived = (payload) => {
    const call = payload?.call;

    if (!call?.call_id) {
        return;
    }

    if (shouldDedupe(`incoming-call:${call.call_id}`)) {
        updateIncomingCallCard(call);

        return;
    }

    showIncomingCallCard(call);
};

export const bindRealtimeNotificationsChannel = (channel) => {
    if (!channel?.listen) {
        return;
    }

    channel.listen('.RealtimeNotificationDelivered', handleRealtimeNotificationDelivered);
    channel.listen('.IncomingCallReceived', handleIncomingCallReceived);
};

export const resetRealtimeNotificationDedupe = () => {
    shownKeys.clear();
    criticalToasts.clear();
};
