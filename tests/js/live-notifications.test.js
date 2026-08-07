import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    bindNotificationDropdownSession,
    flushPendingBellHtml,
    initLiveNotifications,
    pollNotifications,
    resetLiveNotificationsForTests,
    resolveNotificationPollIntervalMs,
    updateUnreadBadge,
} from '../../resources/js/live-notifications';
import { getWorkspaceSession, resetWorkspaceSession } from '../../resources/js/workspace/session';
import {
    resetRealtimeTransportStatusForTests,
    setRealtimeTransportConnected,
} from '../../resources/js/realtime-transport-status';

describe('live notifications session integration', () => {
    beforeEach(() => {
        resetWorkspaceSession();
        resetRealtimeTransportStatusForTests();
        resetLiveNotificationsForTests();
        document.body.innerHTML = `
            <div id="notification-bell-root" data-poll-url="/notifications/poll" data-poll-interval="45000">
                <div class="dropdown">
                    <button type="button" class="notification-bell-btn">
                        <span aria-hidden="true"><i class="bi bi-bell"></i></span>
                        <span class="notification-count-badge">1</span>
                    </button>
                    <div class="dropdown-menu notification-dropdown">
                        <div class="dropdown-item">Original notification</div>
                    </div>
                </div>
            </div>
        `;
    });

    afterEach(() => {
        resetLiveNotificationsForTests();
        resetRealtimeTransportStatusForTests();
        vi.unstubAllGlobals();
        vi.useRealTimers();
        Object.defineProperty(document, 'hidden', {
            configurable: true,
            get: () => false,
        });
    });

    it('updates only the unread badge while the dropdown session is active', async () => {
        const root = document.getElementById('notification-bell-root');
        const session = getWorkspaceSession();

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                unread_count: 2,
                bell_html: `
                    <div class="dropdown">
                        <button type="button" class="notification-bell-btn">
                            <span class="notification-count-badge">2</span>
                        </button>
                        <div class="dropdown-menu notification-dropdown">
                            <div class="dropdown-item">Fresh notification</div>
                        </div>
                    </div>
                `,
                new_notifications: [],
            }),
        }));

        session.acquire('notification-dropdown');

        await pollNotifications({ unreadCount: 1, since: null });

        expect(root.querySelector('.notification-count-badge')?.textContent).toBe('2');
        expect(root.querySelector('.dropdown-item')?.textContent).toBe('Original notification');

        session.release('notification-dropdown');
        vi.unstubAllGlobals();
    });

    it('defers full bell html replacement until the dropdown closes', async () => {
        const root = document.getElementById('notification-bell-root');
        const session = getWorkspaceSession();

        bindNotificationDropdownSession(root);
        session.acquire('notification-dropdown');

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                unread_count: 4,
                bell_html: `
                    <div class="dropdown">
                        <button type="button" class="notification-bell-btn">
                            <span class="notification-count-badge">4</span>
                        </button>
                        <div class="dropdown-menu notification-dropdown">
                            <div class="dropdown-item">Fresh notification</div>
                        </div>
                    </div>
                `,
                new_notifications: [],
            }),
        }));

        await pollNotifications({ unreadCount: 1, since: null });

        expect(root.querySelector('.dropdown-item')?.textContent).toBe('Original notification');

        session.release('notification-dropdown');
        flushPendingBellHtml();

        expect(root.querySelector('.dropdown-item')?.textContent).toBe('Fresh notification');

        vi.unstubAllGlobals();
    });

    it('acquires and releases the notification dropdown session', () => {
        const root = document.getElementById('notification-bell-root');
        const session = getWorkspaceSession();

        bindNotificationDropdownSession(root);

        root.querySelector('.dropdown').dispatchEvent(new Event('show.bs.dropdown'));
        expect(session.isActive('notification-dropdown')).toBe(true);

        root.querySelector('.dropdown').dispatchEvent(new Event('hidden.bs.dropdown'));
        expect(session.isActive('notification-dropdown')).toBe(false);
    });

    it('creates and removes the unread badge without replacing dropdown html', () => {
        const root = document.getElementById('notification-bell-root');

        updateUnreadBadge(root, 9);
        expect(root.querySelector('.notification-count-badge')?.textContent).toBe('9');

        updateUnreadBadge(root, 0);
        expect(root.querySelector('.notification-count-badge')).toBeNull();
    });

    it('uses a 60s safety-net interval while realtime transport is connected', () => {
        const root = document.getElementById('notification-bell-root');

        expect(resolveNotificationPollIntervalMs(root)).toBe(45000);

        setRealtimeTransportConnected(true);
        expect(resolveNotificationPollIntervalMs(root)).toBe(60000);
    });

    it('pauses notification polling while the document is hidden', async () => {
        vi.useFakeTimers();
        let hidden = false;
        Object.defineProperty(document, 'hidden', {
            configurable: true,
            get: () => hidden,
        });

        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                unread_count: 1,
                bell_html: document.getElementById('notification-bell-root').innerHTML,
                new_notifications: [],
            }),
        });
        vi.stubGlobal('fetch', fetchMock);

        initLiveNotifications();
        await Promise.resolve();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        hidden = true;
        document.dispatchEvent(new Event('visibilitychange'));
        await vi.advanceTimersByTimeAsync(120000);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        hidden = false;
        document.dispatchEvent(new Event('visibilitychange'));
        await Promise.resolve();
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
});
