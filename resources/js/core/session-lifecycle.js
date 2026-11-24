export const SESSION_BROADCAST_CHANNEL = 'radium-session';
export const SESSION_LOGOUT_STORAGE_KEY = 'radium.session.logout.signal';
const SESSION_STATUS_PROBE_MIN_INTERVAL_MS = 5000;
const CSRF_RELOAD_GUARD_KEY = 'radium.session.csrf_reload_once';

let broadcastChannel = null;
let logoutRedirectPending = false;
let lastProbeAt = 0;
let probeInFlight = null;
let lifecycleInitialized = false;

const canUseSessionStorage = () => {
    try {
        return typeof window !== 'undefined' && typeof window.sessionStorage !== 'undefined';
    } catch {
        return false;
    }
};

const initBroadcastChannel = () => {
    if (typeof BroadcastChannel === 'undefined') {
        return;
    }

    try {
        broadcastChannel = new BroadcastChannel(SESSION_BROADCAST_CHANNEL);
        broadcastChannel.addEventListener('message', (event) => {
            if (event.data?.type === 'logout') {
                redirectToLogin({ broadcast: false });
            }
        });
    } catch {
        broadcastChannel = null;
    }
};

const initStorageFallback = () => {
    window.addEventListener('storage', (event) => {
        if (event.key !== SESSION_LOGOUT_STORAGE_KEY || event.newValue === null) {
            return;
        }

        redirectToLogin({ broadcast: false });

        try {
            window.localStorage.removeItem(SESSION_LOGOUT_STORAGE_KEY);
        } catch {
            // Ignore quota / private mode errors.
        }
    });
};

const ensureBroadcastChannel = () => {
    if (broadcastChannel !== null) {
        return;
    }

    initBroadcastChannel();
};

export const broadcastSessionLogout = () => {
    ensureBroadcastChannel();

    const payload = { type: 'logout', at: Date.now() };

    broadcastChannel?.postMessage(payload);

    try {
        window.localStorage.setItem(SESSION_LOGOUT_STORAGE_KEY, String(payload.at));
        window.localStorage.removeItem(SESSION_LOGOUT_STORAGE_KEY);
    } catch {
        // Ignore storage failures.
    }
};

export const redirectToLogin = ({ broadcast = true } = {}) => {
    if (logoutRedirectPending) {
        return;
    }

    logoutRedirectPending = true;

    if (broadcast) {
        broadcastSessionLogout();
    }

    window.location.replace('/login');
};

export const isAuthHttpStatus = (status) => status === 401 || status === 419;

export const handleHttpAuthStatus = async (response, { retryAfterCsrf = false } = {}) => {
    if (!response) {
        return { action: 'none' };
    }

    if (response.status === 401) {
        redirectToLogin();

        return { action: 'redirect' };
    }

    if (response.status === 403) {
        return { action: 'forbidden' };
    }

    if (response.status !== 419) {
        return { action: 'none' };
    }

    const statusResponse = await probeSessionStatus();

    if (statusResponse === null) {
        return { action: 'network' };
    }

    if (statusResponse.status === 401) {
        redirectToLogin();

        return { action: 'redirect' };
    }

    if (statusResponse.ok) {
        if (retryAfterCsrf || !canUseSessionStorage()) {
            window.location.reload();

            return { action: 'reload' };
        }

        const alreadyReloaded = window.sessionStorage.getItem(CSRF_RELOAD_GUARD_KEY) === '1';

        if (alreadyReloaded) {
            return { action: 'csrf_stale' };
        }

        window.sessionStorage.setItem(CSRF_RELOAD_GUARD_KEY, '1');
        window.location.reload();

        return { action: 'reload' };
    }

    return { action: 'none' };
};

export const probeSessionStatus = async () => {
    const url = document.body?.dataset?.sessionStatusUrl?.trim() ?? '';

    if (url === '' || document.body?.dataset?.sessionLifecycle !== 'true') {
        return null;
    }

    const now = Date.now();

    if (now - lastProbeAt < SESSION_STATUS_PROBE_MIN_INTERVAL_MS && probeInFlight !== null) {
        return probeInFlight;
    }

    lastProbeAt = now;

    probeInFlight = fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    }).catch(() => null).finally(() => {
        probeInFlight = null;
    });

    return probeInFlight;
};

const runResumeProbe = () => {
    if (document.body?.dataset?.sessionLifecycle !== 'true') {
        return;
    }

    if (document.visibilityState === 'hidden') {
        return;
    }

    void probeSessionStatus().then((response) => {
        if (response === null) {
            return;
        }

        if (response.status === 401) {
            redirectToLogin();
        }
    });
};

const bindLogoutForms = () => {
    document.querySelectorAll('[data-session-logout-form]').forEach((form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.sessionLogoutBound === 'true') {
            return;
        }

        form.dataset.sessionLogoutBound = 'true';
        form.addEventListener('submit', () => {
            broadcastSessionLogout();
        });
    });
};

export const initSessionLifecycle = () => {
    if (lifecycleInitialized || document.body?.dataset?.sessionLifecycle !== 'true') {
        return;
    }

    lifecycleInitialized = true;
    initBroadcastChannel();
    initStorageFallback();
    bindLogoutForms();

    const onResume = () => runResumeProbe();

    document.addEventListener('visibilitychange', onResume);
    window.addEventListener('focus', onResume);
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            onResume();
        }
    });
};

export const resetSessionLifecycleForTests = () => {
    logoutRedirectPending = false;
    lastProbeAt = 0;
    probeInFlight = null;
    lifecycleInitialized = false;
    broadcastChannel?.close?.();
    broadcastChannel = null;
};