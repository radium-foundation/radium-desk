import { getWorkspaceSession } from './workspace/session';
import { maybeHandleIncomingCallInteraction } from './incoming-call-interaction';

const animateNotificationBell = () => {
    const bellButton = document.querySelector('.notification-bell-btn');

    if (!bellButton) {
        return;
    }

    bellButton.classList.remove('notification-bell-animate');
    void bellButton.offsetWidth;
    bellButton.classList.add('notification-bell-animate');
    bellButton.addEventListener('animationend', () => {
        bellButton.classList.remove('notification-bell-animate');
    }, { once: true });
};

const showBrowserNotification = (item) => {
    if (!('Notification' in window) || Notification.permission !== 'granted') {
        return;
    }

    try {
        const notification = new Notification(item.title, {
            body: item.message,
            tag: item.id,
        });

        notification.onclick = () => {
            window.focus();

            if (item.url) {
                window.location.href = item.url;
            }

            notification.close();
        };
    } catch (error) {
        // Gracefully ignore blocked or unsupported browser notifications.
    }
};

const replaceNotificationBell = (html) => {
    const root = document.getElementById('notification-bell-root');

    if (!root || !html) {
        return;
    }

    root.innerHTML = html;
    bindNotificationDropdownSession(root);
};

const updateUnreadBadge = (root, unreadCount) => {
    const bellButton = root.querySelector('.notification-bell-btn');

    if (!bellButton) {
        return;
    }

    let badge = bellButton.querySelector('.notification-count-badge');

    if (unreadCount <= 0) {
        badge?.remove();

        return;
    }

    if (!badge) {
        badge = document.createElement('span');
        badge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notification-count-badge';
        bellButton.appendChild(badge);
    }

    badge.textContent = String(unreadCount);
};

let pendingBellHtml = null;

const flushPendingBellHtml = () => {
    if (!pendingBellHtml) {
        return;
    }

    const html = pendingBellHtml;
    pendingBellHtml = null;
    replaceNotificationBell(html);
};

const bindNotificationDropdownSession = (root) => {
    const dropdown = root.querySelector('.dropdown');

    if (!dropdown || dropdown.dataset.sessionBound === 'true') {
        return;
    }

    dropdown.dataset.sessionBound = 'true';
    const session = getWorkspaceSession();

    dropdown.addEventListener('show.bs.dropdown', () => {
        session.acquire('notification-dropdown');
    });

    dropdown.addEventListener('hidden.bs.dropdown', () => {
        session.release('notification-dropdown');
        flushPendingBellHtml();
    });
};

const pollNotifications = async (state) => {
    const root = document.getElementById('notification-bell-root');
    const pollUrl = root?.dataset.pollUrl;

    if (!pollUrl || document.hidden) {
        return;
    }

    const url = new URL(pollUrl, window.location.origin);

    if (state.since) {
        url.searchParams.set('since', state.since);
    }

    try {
        const response = await fetch(url.toString(), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            return;
        }

        const data = await response.json();
        const unreadCount = Number(data.unread_count ?? 0);

        if (state.unreadCount !== null && unreadCount !== state.unreadCount) {
            animateNotificationBell();
        }

        if (getWorkspaceSession().isActive('notification-dropdown')) {
            pendingBellHtml = data.bell_html;
            updateUnreadBadge(root, unreadCount);
        } else {
            pendingBellHtml = null;
            replaceNotificationBell(data.bell_html);
        }

        state.unreadCount = unreadCount;
        state.since = new Date().toISOString();

        (data.new_notifications ?? []).forEach((item) => {
            showBrowserNotification(item);
            maybeHandleIncomingCallInteraction(item.interaction);
        });
    } catch (error) {
        // Ignore transient network errors during background polling.
    }
};

export const initLiveNotifications = () => {
    const root = document.getElementById('notification-bell-root');

    if (!root?.dataset.pollUrl) {
        return;
    }

    bindNotificationDropdownSession(root);

    const intervalMs = Number(root.dataset.pollInterval ?? 20000);
    const state = {
        unreadCount: null,
        since: null,
    };

    const tick = () => {
        pollNotifications(state);
    };

    window.setInterval(tick, intervalMs);
    tick();
};

export {
    animateNotificationBell,
    bindNotificationDropdownSession,
    flushPendingBellHtml,
    pollNotifications,
    replaceNotificationBell,
    showBrowserNotification,
    updateUnreadBadge,
};
