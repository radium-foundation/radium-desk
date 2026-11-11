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

const setErrorState = (host) => {
    host.setAttribute('aria-busy', 'false');
    const loading = host.querySelector('[data-hardware-stuck-loading]');
    const status = host.querySelector('[data-hardware-stuck-status]');
    const error = host.querySelector('[data-hardware-stuck-error]');
    if (loading) {
        loading.textContent = 'Unavailable';
        loading.classList.add('text-danger');
    }
    if (status) {
        status.textContent = 'Stuck order counts are temporarily unavailable.';
        status.classList.add('text-danger');
    }
    if (error) {
        error.classList.remove('d-none');
        error.hidden = false;
    }
};

const fetchStuckSummary = async (host) => {
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

const applyStuckSummaryHtml = (host, html) => {
    const wrapper = document.createElement('div');
    wrapper.innerHTML = html.trim();
    const next = wrapper.firstElementChild;
    if (!next) {
        setErrorState(host);
        return;
    }
    if (host.dataset.hardwareStuckUrl) {
        next.dataset.hardwareStuckLoaded = '1';
        next.dataset.hardwareStuckUrl = host.dataset.hardwareStuckUrl;
        next.dataset.hardwareStuckRefreshMs = host.dataset.hardwareStuckRefreshMs || '';
    }
    host.replaceWith(next);
};

const findStuckSummaryHost = (root) => (
    root.querySelector('[data-hardware-stuck-summary]')
    || root.querySelector('[data-hardware-stuck-loaded="1"]')
);

const loadStuckSummary = async (host) => {
    if (document.hidden) {
        return;
    }

    try {
        const payload = await fetchStuckSummary(host);
        if (!payload) {
            setErrorState(host);
            return;
        }
        applyStuckSummaryHtml(host, payload.html);
    } catch {
        setErrorState(host);
    }
};

export const initHardwareStuckSummaryAsync = (root = document) => {
    const host = root.querySelector('[data-hardware-stuck-summary]');
    if (!host) {
        return;
    }

    if (stuckSummaryRefreshHandle !== null) {
        window.clearInterval(stuckSummaryRefreshHandle);
        stuckSummaryRefreshHandle = null;
    }

    void loadStuckSummary(host);

    const refreshMs = parseRefreshMs(host);
    stuckSummaryRefreshHandle = window.setInterval(() => {
        const current = findStuckSummaryHost(root);
        if (!current || document.hidden) {
            return;
        }
        void loadStuckSummary(current);
    }, refreshMs);
};
