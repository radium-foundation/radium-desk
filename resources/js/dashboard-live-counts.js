/**
 * View-only dashboard KPI counts (E: stale-aware lazy refresh, C: manual per-metric refresh).
 *
 * Never calls GET /dashboard/live — only GET /dashboard/live/counts?metric=…
 */

const formatCount = (count) => new Intl.NumberFormat().format(count);

/** Metrics backed by indexed SQL aggregates (see docs/dashboard-architecture.md). */
export const VIEW_ONLY_METRIC_IDS = Object.freeze(['active_cases', 'pending_refunds']);

const metricState = new Map();
const inFlight = new Map();
let updatedAgoIntervalId = null;

const parseFormattedCount = (text) => {
    const normalized = String(text ?? '').replace(/,/g, '').trim();

    if (normalized === '' || Number.isNaN(Number(normalized))) {
        return null;
    }

    return Number(normalized);
};

const resolveFreshnessMs = (pageRoot) => {
    const idleSeconds = Number(pageRoot?.dataset?.liveIntervalIdle ?? 120);

    if (idleSeconds > 0) {
        return idleSeconds * 1000;
    }

    return 120_000;
};

const getMetricItem = (pageRoot, metric) => pageRoot?.querySelector(`[data-dashboard-metric="${metric}"]`);

export const formatMetricUpdatedAgo = (fetchedAt, now = Date.now()) => {
    if (!fetchedAt) {
        return '';
    }

    const seconds = Math.max(0, Math.floor((now - fetchedAt) / 1000));

    if (seconds < 10) {
        return 'Updated just now';
    }

    if (seconds < 60) {
        return `Updated ${seconds}s ago`;
    }

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) {
        return `Updated ${minutes}m ago`;
    }

    const hours = Math.floor(minutes / 60);

    return `Updated ${hours}h ago`;
};

const setMetricUiState = (pageRoot, metric, { loading = false, error = null } = {}) => {
    const item = getMetricItem(pageRoot, metric);

    if (!item) {
        return;
    }

    item.classList.toggle('is-metric-refreshing', loading);
    item.classList.toggle('is-metric-error', Boolean(error));

    const refreshBtn = item.querySelector(`[data-dashboard-metric-refresh="${metric}"]`);

    if (refreshBtn) {
        refreshBtn.disabled = loading;
        refreshBtn.setAttribute('aria-busy', loading ? 'true' : 'false');
    }

    const updatedEl = item.querySelector(`[data-dashboard-metric-updated="${metric}"]`);

    if (updatedEl && error) {
        updatedEl.textContent = 'Update failed';
        updatedEl.title = error;
    } else if (updatedEl && !loading) {
        const state = metricState.get(metric);
        updatedEl.textContent = formatMetricUpdatedAgo(state?.fetchedAt ?? null);
        updatedEl.removeAttribute('title');
    }
};

const syncUpdatedAgoLabels = (pageRoot) => {
    VIEW_ONLY_METRIC_IDS.forEach((metric) => {
        const state = metricState.get(metric);
        const updatedEl = pageRoot?.querySelector(`[data-dashboard-metric-updated="${metric}"]`);

        if (!updatedEl || !state?.fetchedAt) {
            return;
        }

        updatedEl.textContent = formatMetricUpdatedAgo(state.fetchedAt);
    });
};

export const seedViewOnlyMetricFromDom = (pageRoot, metric) => {
    if (!pageRoot || !VIEW_ONLY_METRIC_IDS.includes(metric)) {
        return;
    }

    const valueEl = getMetricItem(pageRoot, metric)?.querySelector('.dashboard-kpi-value');
    const count = parseFormattedCount(valueEl?.textContent);

    if (count === null) {
        return;
    }

    metricState.set(metric, {
        count,
        fetchedAt: Date.now(),
        error: null,
    });

    setMetricUiState(pageRoot, metric);
};

export const isViewOnlyMetricStale = (pageRoot, metric) => {
    const state = metricState.get(metric);

    if (!state?.fetchedAt) {
        return true;
    }

    return Date.now() - state.fetchedAt >= resolveFreshnessMs(pageRoot);
};

export const fetchDashboardLiveCount = async (pageRoot, metric) => {
    const baseUrl = pageRoot?.dataset?.liveCountsUrl;

    if (!baseUrl || !metric) {
        return null;
    }

    const url = `${baseUrl}?metric=${encodeURIComponent(metric)}`;
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`Count request failed (${response.status})`);
    }

    return response.json();
};

export const applyDashboardMetricCount = (pageRoot, metric, count) => {
    if (!pageRoot || !metric || !Number.isFinite(count)) {
        return;
    }

    const valueEl = getMetricItem(pageRoot, metric)?.querySelector('.dashboard-kpi-value');

    if (valueEl) {
        valueEl.textContent = formatCount(count);
    }

    metricState.set(metric, {
        count,
        fetchedAt: Date.now(),
        error: null,
    });

    setMetricUiState(pageRoot, metric);
};

export const refreshViewOnlyMetric = async (pageRoot, metric, { force = false } = {}) => {
    if (!pageRoot || !VIEW_ONLY_METRIC_IDS.includes(metric)) {
        return null;
    }

    if (!force && !isViewOnlyMetricStale(pageRoot, metric)) {
        return metricState.get(metric)?.count ?? null;
    }

    if (inFlight.has(metric)) {
        return inFlight.get(metric);
    }

    const promise = (async () => {
        setMetricUiState(pageRoot, metric, { loading: true, error: null });

        try {
            const data = await fetchDashboardLiveCount(pageRoot, metric);

            if (data?.metric !== metric || !Number.isFinite(data.count)) {
                throw new Error('Invalid count response');
            }

            applyDashboardMetricCount(pageRoot, metric, data.count);

            return data.count;
        } catch (error) {
            const message = error?.message ?? 'Update failed';
            const previous = metricState.get(metric);
            metricState.set(metric, {
                count: previous?.count ?? null,
                fetchedAt: previous?.fetchedAt ?? null,
                error: message,
            });

            return null;
        } finally {
            inFlight.delete(metric);
            const state = metricState.get(metric);
            setMetricUiState(pageRoot, metric, {
                loading: false,
                error: state?.error ?? null,
            });
        }
    })();

    inFlight.set(metric, promise);

    return promise;
};

export const ensureViewOnlyMetricFresh = (pageRoot, metric) => refreshViewOnlyMetric(pageRoot, metric, { force: false });

export const reconcileViewOnlyMetrics = async (pageRoot) => {
    if (!pageRoot?.dataset?.liveCountsUrl) {
        return;
    }

    const metrics = VIEW_ONLY_METRIC_IDS.filter((metric) => getMetricItem(pageRoot, metric));

    await Promise.all(metrics.map((metric) => refreshViewOnlyMetric(pageRoot, metric, { force: false })));
};

/** @deprecated Use refreshViewOnlyMetric(pageRoot, 'active_cases') */
export const refreshActiveCasesCount = async (pageRoot) => refreshViewOnlyMetric(pageRoot, 'active_cases', { force: false });

export const initViewOnlyMetricRefresh = (pageRoot = document.getElementById('dashboard-page')) => {
    if (!pageRoot?.dataset?.liveCountsUrl) {
        return null;
    }

    VIEW_ONLY_METRIC_IDS.forEach((metric) => {
        if (getMetricItem(pageRoot, metric)) {
            seedViewOnlyMetricFromDom(pageRoot, metric);
        }
    });

    pageRoot.addEventListener('click', (event) => {
        const refreshBtn = event.target.closest('[data-dashboard-metric-refresh]');

        if (!refreshBtn || !pageRoot.contains(refreshBtn)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const metric = refreshBtn.dataset.dashboardMetricRefresh;

        if (!metric || refreshBtn.disabled) {
            return;
        }

        void refreshViewOnlyMetric(pageRoot, metric, { force: true });
    });

    if (updatedAgoIntervalId === null) {
        updatedAgoIntervalId = window.setInterval(() => {
            const root = document.getElementById('dashboard-page');

            if (root) {
                syncUpdatedAgoLabels(root);
            }
        }, 30_000);
    }

    syncUpdatedAgoLabels(pageRoot);

    return {
        ensureFresh: (metric) => ensureViewOnlyMetricFresh(pageRoot, metric),
        reconcile: () => reconcileViewOnlyMetrics(pageRoot),
    };
};

export const resetViewOnlyMetricStateForTests = () => {
    metricState.clear();
    inFlight.clear();

    if (updatedAgoIntervalId !== null) {
        window.clearInterval(updatedAgoIntervalId);
        updatedAgoIntervalId = null;
    }
};
