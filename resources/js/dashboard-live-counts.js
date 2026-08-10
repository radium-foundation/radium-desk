const formatCount = (count) => new Intl.NumberFormat().format(count);

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
        return null;
    }

    return response.json();
};

export const applyDashboardMetricCount = (pageRoot, metric, count) => {
    if (!pageRoot || !metric || !Number.isFinite(count)) {
        return;
    }

    const valueEl = pageRoot.querySelector(
        `[data-dashboard-metric="${metric}"] .dashboard-kpi-value`,
    );

    if (valueEl) {
        valueEl.textContent = formatCount(count);
    }
};

export const refreshActiveCasesCount = async (pageRoot) => {
    const data = await fetchDashboardLiveCount(pageRoot, 'active_cases');

    if (data?.metric !== 'active_cases' || !Number.isFinite(data.count)) {
        return null;
    }

    applyDashboardMetricCount(pageRoot, 'active_cases', data.count);

    return data.count;
};
