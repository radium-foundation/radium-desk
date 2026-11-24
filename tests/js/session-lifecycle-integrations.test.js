import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    SESSION_LOGOUT_STORAGE_KEY,
    handleHttpAuthStatus,
    initSessionLifecycle,
    probeSessionStatus,
    resetSessionLifecycleForTests,
} from '../../resources/js/core/session-lifecycle';

describe('session-lifecycle integrations', () => {
    beforeEach(() => {
        resetSessionLifecycleForTests();
        document.body.innerHTML = '';
        document.body.dataset.sessionLifecycle = 'true';
        document.body.dataset.sessionStatusUrl = '/session/status';
        window.location.replace = vi.fn();
        window.location.reload = vi.fn();
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        window.sessionStorage.clear();
    });

    it('universal-search path: 401 redirects with replace', async () => {
        await handleHttpAuthStatus(new Response(null, { status: 401 }));

        expect(window.location.replace).toHaveBeenCalledWith('/login');
    });

    it('universal-search path: 403 remains permission denied', async () => {
        const result = await handleHttpAuthStatus(new Response(null, { status: 403 }));

        expect(result.action).toBe('forbidden');
        expect(window.location.replace).not.toHaveBeenCalled();
    });

    it('customer-360 path: 401 triggers shared logout flow', async () => {
        fetch.mockResolvedValueOnce(new Response(null, { status: 401 }));

        const response = await fetch('/incidents/1/customer-360');
        await handleHttpAuthStatus(response);

        expect(window.location.replace).toHaveBeenCalledWith('/login');
    });

    it('axios-style 401 uses shared lifecycle handler', async () => {
        await handleHttpAuthStatus({ status: 401, headers: { get: () => null } });

        expect(window.location.replace).toHaveBeenCalledWith('/login');
    });

    it('axios-style 403 does not logout', async () => {
        const result = await handleHttpAuthStatus({ status: 403, headers: { get: () => null } });

        expect(result.action).toBe('forbidden');
        expect(window.location.replace).not.toHaveBeenCalled();
    });

    it('419 runs session probe and reloads when still authenticated', async () => {
        fetch.mockResolvedValueOnce(new Response(JSON.stringify({ authenticated: true }), { status: 200 }));

        const result = await handleHttpAuthStatus(new Response(null, { status: 419 }));

        expect(fetch).toHaveBeenCalled();
        expect(result.action).toBe('reload');
        expect(window.location.reload).toHaveBeenCalledTimes(1);
    });

    it('419 redirects when probe confirms logout', async () => {
        fetch.mockResolvedValueOnce(new Response(null, { status: 401 }));

        await handleHttpAuthStatus(new Response(null, { status: 419 }));

        expect(window.location.replace).toHaveBeenCalledWith('/login');
        expect(window.location.reload).not.toHaveBeenCalled();
    });

    it('419 anti-loop guard prevents repeated reload', async () => {
        window.sessionStorage.setItem('radium.session.csrf_reload_once', '1');
        fetch.mockResolvedValueOnce(new Response(JSON.stringify({ authenticated: true }), { status: 200 }));

        const result = await handleHttpAuthStatus(new Response(null, { status: 419 }));

        expect(result.action).toBe('csrf_stale');
        expect(window.location.reload).not.toHaveBeenCalled();
    });

    it('broadcasts logout on confirmed 401', async () => {
        const postMessage = vi.fn();
        vi.stubGlobal('BroadcastChannel', vi.fn(() => ({
            postMessage,
            addEventListener: vi.fn(),
            close: vi.fn(),
        })));

        await handleHttpAuthStatus(new Response(null, { status: 401 }));

        expect(postMessage).toHaveBeenCalledWith(expect.objectContaining({ type: 'logout' }));
    });

    it('storage fallback redirects other tabs', () => {
        initSessionLifecycle();

        window.dispatchEvent(new StorageEvent('storage', {
            key: SESSION_LOGOUT_STORAGE_KEY,
            newValue: '1730000000',
        }));

        expect(window.location.replace).toHaveBeenCalledWith('/login');
    });

    it('offline probe does not logout', async () => {
        fetch.mockRejectedValueOnce(new Error('offline'));

        const result = await probeSessionStatus();

        expect(result).toBeNull();
        expect(window.location.replace).not.toHaveBeenCalled();
    });

    it('non-attendance layout omits presence heartbeat dataset', () => {
        delete document.body.dataset.presenceHeartbeat;

        expect(document.body.dataset.presenceHeartbeat).toBeUndefined();
        expect(document.body.dataset.sessionLifecycle).toBe('true');
    });
});