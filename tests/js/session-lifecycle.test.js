import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    SESSION_BROADCAST_CHANNEL,
    SESSION_LOGOUT_STORAGE_KEY,
    broadcastSessionLogout,
    handleHttpAuthStatus,
    initSessionLifecycle,
    probeSessionStatus,
    redirectToLogin,
    resetSessionLifecycleForTests,
} from '../../resources/js/core/session-lifecycle';

describe('session-lifecycle', () => {
    beforeEach(() => {
        resetSessionLifecycleForTests();
        document.body.innerHTML = '';
        document.body.dataset.sessionLifecycle = 'true';
        document.body.dataset.sessionStatusUrl = '/session/status';
        vi.stubGlobal('fetch', vi.fn());
        window.location.replace = vi.fn();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('redirects on confirmed 401 using location.replace', async () => {
        const response = new Response(null, { status: 401 });

        await handleHttpAuthStatus(response);

        expect(window.location.replace).toHaveBeenCalledWith('/login');
    });

    it('does not redirect on 403', async () => {
        const response = new Response(null, { status: 403 });

        const result = await handleHttpAuthStatus(response);

        expect(result.action).toBe('forbidden');
        expect(window.location.replace).not.toHaveBeenCalled();
    });

    it('probes session status on 419 before reload', async () => {
        fetch
            .mockResolvedValueOnce(new Response(JSON.stringify({ authenticated: true }), { status: 200 }))
            .mockResolvedValueOnce(new Response(null, { status: 200 }));

        const response = new Response(null, { status: 419 });
        const reloadSpy = vi.spyOn(window.location, 'reload').mockImplementation(() => {});

        const result = await handleHttpAuthStatus(response);

        expect(fetch).toHaveBeenCalled();
        expect(result.action).toBe('reload');
        expect(reloadSpy).toHaveBeenCalled();
    });

    it('419 after unauthenticated probe redirects to login', async () => {
        fetch.mockResolvedValueOnce(new Response(null, { status: 401 }));

        const response = new Response(null, { status: 419 });
        await handleHttpAuthStatus(response);

        expect(window.location.replace).toHaveBeenCalledWith('/login');
    });

    it('visibilitychange resume triggers a session probe', async () => {
        fetch.mockResolvedValue(new Response(JSON.stringify({ authenticated: true }), { status: 200 }));

        initSessionLifecycle();
        document.dispatchEvent(new Event('visibilitychange'));

        await vi.waitFor(() => {
            expect(fetch).toHaveBeenCalled();
        });
    });

    it('broadcasts logout through BroadcastChannel when available', () => {
        const postMessage = vi.fn();
        vi.stubGlobal('BroadcastChannel', vi.fn(() => ({
            postMessage,
            addEventListener: vi.fn(),
            close: vi.fn(),
        })));

        broadcastSessionLogout();

        expect(BroadcastChannel).toHaveBeenCalledWith(SESSION_BROADCAST_CHANNEL);
        expect(postMessage).toHaveBeenCalledWith(expect.objectContaining({ type: 'logout' }));
    });

    it('uses localStorage fallback marker for cross-tab logout', () => {
        const setItem = vi.spyOn(window.localStorage, 'setItem');
        const removeItem = vi.spyOn(window.localStorage, 'removeItem');

        broadcastSessionLogout();

        expect(setItem).toHaveBeenCalledWith(SESSION_LOGOUT_STORAGE_KEY, expect.any(String));
        expect(removeItem).toHaveBeenCalledWith(SESSION_LOGOUT_STORAGE_KEY);
    });

    it('does not logout on failed probe network error', async () => {
        fetch.mockRejectedValueOnce(new Error('network down'));

        const result = await probeSessionStatus();

        expect(result).toBeNull();
        expect(window.location.replace).not.toHaveBeenCalled();
    });

    it('redirectToLogin only runs once', () => {
        redirectToLogin();
        redirectToLogin();

        expect(window.location.replace).toHaveBeenCalledTimes(1);
    });
});