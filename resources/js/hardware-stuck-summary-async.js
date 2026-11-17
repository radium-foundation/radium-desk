import { formatMetricUpdatedAgo } from './dashboard-live-counts';

let stuckSummaryInFlight = null;
let stuckSummaryRefreshHandle = null;

const parseRefreshMs = (host) => {
    const raw = host?.dataset?.hardwareStuckRefreshMs;
    const parsed = Number(raw);
    if (!Number.isFinite(parsed) || parsed < 5000) {
        return 30000;
    }

    return parsed;
};

const parseVersion = (host) => {
    const raw = host?.dataset?.hardwareStuckVersion;
    const parsed = Number(raw);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
};

const setErrorState = (host) => {
    host.setAttribute('aria-busy', 'false');
    const status = host.querySelector('[data-hardware-stuck-status]');
    const error = host.querySelector('[data-hardware-stuck-error]');
    if (status && host.dataset.hardwareStuckSummary !== undefined) {
        status.textContent = 'Stuck order counts are temporarily unavailable.';
        status.classList.add('text-danger');
    }
    if (error) {
        error.classList.remove('d-none');
        error.hidden = false;
    }
};

const syncUpdatedLabel = (host) => {
    const updatedEl = host.querySelector('[data-hardware-stuck-updated]');
    const capturedAt = Number(host.dataset.hardwareStuckCapturedAt);
    if (!updatedEl || !Number.isFinite(capturedAt) || capturedAt <= 0) {
        return;
    }

    updatedEl.textContent = formatMetricUpdatedAgo(capturedAt * 1000);
};

const fetchStuckSummary = async (host, { background = false } = {}) => {
    const url = host.dataset.hardwareStuckUrl;
    if (!url) {
        return null;
    }

    if (stuckSummaryInFlight) {
        return stuckSummaryInFlight;
    }

    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 60000);

    stuckSummaryInFlight = (async () => {
        try {
            const response = await fetch(url, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            if (!response.ok) {
                if (background && host.dataset.hardwareStuckLoaded === '1') {
                    return null;
                }
                throw new Error(`HTTP ${response.status}`);
            }
            const payload = await response.json();
            if (typeof payload.html !== 'string' || payload.html.trim() === '') {
                throw new Error('Invalid stuck summary payload');
            }

            return payload;
        } finally {
            window.clearTimeout(timeout);
            stuckSummaryInFlight = null;
        }
    })();

    return stuckSummaryInFlight;
};

export const applyHardwareStuckSummaryHtml = (host, html, meta = {}) => {
    const wrapper = document.createElement('div');
    wrapper.innerHTML = html.trim();
    const next = wrapper.firstElementChild;
    if (!next) {
        if (host) {
            setErrorState(host);
        }

        return false;
    }

    const incomingVersion = Number(meta.version ?? next.dataset.hardwareStuckVersion);
    const currentVersion = parseVersion(host);
    if (currentVersion !== null && Number.isFinite(incomingVersion) && incomingVersion > 0
        && incomingVersion < currentVersion) {
        return false;
    }

    if (host?.dataset?.hardwareStuckUrl) {
        next.dataset.hardwareStuckUrl = host.dataset.hardwareStuckUrl;
        next.dataset.hardwareStuckRefreshMs = host.dataset.hardwareStuckRefreshMs || '';
    }

    host.replaceWith(next);
    syncUpdatedLabel(next);

    return true;
};

const applyStuckSummaryPayload = (host, payload) => {
    const nextVersion = Number(payload.version);
    const currentVersion = parseVersion(host);
    if (currentVersion !== null && Number.isFinite(nextVersion) && nextVersion > 0
        && nextVersion < currentVersion) {
        return;
    }

    applyHardwareStuckSummaryHtml(host, payload.html, {
        version: payload.version,
    });
};

const findStuckSummaryHost = (root) => (
    root.querySelector('[data-hardware-stuck-summary]')
    || root.querySelector('[data-hardware-stuck-loaded="1"]')
);

const loadStuckSummary = async (host, { background = false } = {}) => {
    if (document.hidden) {
        return;
    }

    const hasRenderedCounts = host.dataset.hardwareStuckLoaded === '1';
    if (background && hasRenderedCounts) {
        host.classList.add('admin-hardware-stuck-kpi--refreshing');
    }

    try {
        const payload = await fetchStuckSummary(host, { background });
        if (!payload) {
            if (!background || !hasRenderedCounts) {
                setErrorState(host);
            }

            return;
        }

        if (hasRenderedCounts) {
            applyStuckSummaryPayload(host, payload);
        } else {
            applyStuckSummaryPayload(host, payload);
        }
    } catch {
        if (!background || !hasRenderedCounts) {
            setErrorState(host);
        }
    } finally {
        const current = findStuckSummaryHost(document);
        current?.classList.remove('admin-hardware-stuck-kpi--refreshing');
    }
};

export const applyHardwareStuckOrdersRealtimePayload = (root, payload) => {
    const host = findStuckSummaryHost(root);
    if (!host || typeof payload?.html !== 'string') {
        return;
    }

    applyStuckSummaryPayload(host, payload);
};

export const initHardwareStuckSummaryAsync = (root = document) => {
    const host = findStuckSummaryHost(root);
    if (!host) {
        return;
    }

    syncUpdatedLabel(host);

    if (stuckSummaryRefreshHandle !== null) {
        window.clearInterval(stuckSummaryRefreshHandle);
        stuckSummaryRefreshHandle = null;
    }

    const background = host.dataset.hardwareStuckLoaded === '1';
    void loadStuckSummary(host, { background });

    const refreshMs = parseRefreshMs(host);
    stuckSummaryRefreshHandle = window.setInterval(() => {
        const current = findStuckSummaryHost(root);
        if (!current || document.hidden) {
            return;
        }
        void loadStuckSummary(current, { background: true });
    }, refreshMs);
};