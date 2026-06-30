import { appendServiceCaseRows } from './live-dashboard-merge';
import { initTooltips } from './tooltips';
import { isDashboardSearchActive } from './dashboard-search-mode';
import {
    appendServiceCaseLoadedCount,
    getServiceCaseLoadedCount,
    setServiceCasePagination,
} from './dashboard-service-case-state';

export const initDashboardLoadMore = ({
    pageRoot = document,
    onRowsAppended = null,
} = {}) => {
    const card = pageRoot.querySelector('.dashboard-service-cases-card');
    const button = pageRoot.querySelector('[data-dashboard-load-more]');
    const loadMoreUrl = pageRoot.dataset.dashboardLoadMoreUrl;

    if (!card || !button || !loadMoreUrl) {
        return null;
    }

    let loading = false;

    const loadMore = async () => {
        if (loading || isDashboardSearchActive()) {
            return;
        }

        loading = true;
        button.disabled = true;

        try {
            const filter = pageRoot.dataset.liveFilter ?? card.dataset.serviceCaseFilter ?? 'pending_admin';
            const view = pageRoot.dataset.liveView ?? 'all';
            const query = new URLSearchParams({
                filter,
                offset: String(getServiceCaseLoadedCount()),
            });

            if (view && view !== 'all') {
                query.set('view', view);
            }

            const response = await fetch(`${loadMoreUrl}?${query.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            const rows = data.rows ?? [];

            if (rows.length > 0) {
                appendServiceCaseRows(card, rows, initTooltips);
                appendServiceCaseLoadedCount(rows.length);
                onRowsAppended?.(rows.map(({ incident_id: incidentId }) => incidentId));
            }

            setServiceCasePagination({
                loaded: data.loaded_count ?? getServiceCaseLoadedCount(),
                total: data.total_count,
            });
        } catch (error) {
            // Ignore transient network errors during load more.
        } finally {
            loading = false;
            button.disabled = false;
        }
    };

    button.addEventListener('click', () => {
        loadMore();
    });

    return { loadMore };
};
