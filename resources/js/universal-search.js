import { isDashboardSearchActive, setDashboardSearchActive } from './dashboard-search-mode';
import { hideSearchBanner, showSearchBanner } from './dashboard-search-banner';

const SEARCH_ICON_HTML = '<i class="bi bi-search"></i>';
const SEARCH_LOADING_HTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
const SEARCH_MATCH_CLASS = 'dashboard-case-row--search-match';

const buildSearchRowsUrl = (baseUrl, incidentIds) => {
    const params = new URLSearchParams();

    incidentIds.forEach((incidentId) => {
        params.append('ids[]', String(incidentId));
    });

    return `${baseUrl}?${params.toString()}`;
};

const clearSearchMatchHighlight = (card) => {
    card?.querySelectorAll(`.${SEARCH_MATCH_CLASS}`).forEach((row) => {
        row.classList.remove(SEARCH_MATCH_CLASS);
    });
};

const highlightSearchMatch = (card, incidentId) => {
    clearSearchMatchHighlight(card);

    const row = document.getElementById(`service-case-row-${incidentId}`);

    if (!row) {
        return;
    }

    row.classList.add(SEARCH_MATCH_CLASS);

    const scrollContainer = card.querySelector('#dashboard-service-cases-scroll');

    if (scrollContainer) {
        const rowTop = row.offsetTop - scrollContainer.offsetTop;
        const rowBottom = rowTop + row.offsetHeight;
        const viewTop = scrollContainer.scrollTop;
        const viewBottom = viewTop + scrollContainer.clientHeight;

        if (rowTop < viewTop || rowBottom > viewBottom) {
            row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }
};

export const initUniversalSearch = ({
    dashboardIntegration = null,
} = {}) => {
    const form = document.querySelector('[data-universal-search-form]');
    const globalInput = document.getElementById('global-search-input');
    const searchUrl = form?.dataset.searchUrl ?? '';
    const searchControl = document.querySelector('[data-universal-search-control]');
    const searchIcon = document.querySelector('[data-universal-search-icon]');

    let searchRequestId = 0;
    let searchAbortController = null;

    const setSearchLoading = (loading) => {
        if (!searchIcon) {
            return;
        }

        searchControl?.toggleAttribute('aria-busy', loading);
        searchIcon.innerHTML = loading ? SEARCH_LOADING_HTML : SEARCH_ICON_HTML;
    };

    const getDashboardCard = () => (
        dashboardIntegration?.pageRoot?.querySelector('.dashboard-service-cases-card') ?? null
    );

    const applySearchRows = async (incidentIds, matchCount) => {
        if (!dashboardIntegration?.searchRowsUrl || !dashboardIntegration.applyRows) {
            return;
        }

        const card = getDashboardCard();

        if (!card || incidentIds.length === 0) {
            return;
        }

        const rowsResponse = await fetch(
            buildSearchRowsUrl(dashboardIntegration.searchRowsUrl, incidentIds),
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: searchAbortController?.signal,
            },
        );

        if (!rowsResponse.ok) {
            return;
        }

        const rowsData = await rowsResponse.json();

        dashboardIntegration.applyRows(rowsData.rows ?? [], {
            serviceCasesEmpty: Boolean(rowsData.service_cases_empty),
            serviceCasesEmptyHtml: rowsData.service_cases_empty_html ?? '',
        });
        dashboardIntegration.onRowsUpdated?.();

        if (matchCount === 1 && incidentIds.length === 1) {
            const incidentId = incidentIds[0];
            highlightSearchMatch(card, incidentId);

            const row = document.getElementById(`service-case-row-${incidentId}`);
            const referenceLabel = row?.querySelector('.case-reference-link')?.textContent?.trim() ?? '';

            await dashboardIntegration.openDrawer?.(incidentId, referenceLabel);
        } else {
            clearSearchMatchHighlight(card);
        }
    };

    const restoreDashboard = async () => {
        setDashboardSearchActive(false);
        hideSearchBanner(getDashboardCard());
        clearSearchMatchHighlight(getDashboardCard());
        dashboardIntegration?.closeDrawer?.();

        if (dashboardIntegration?.restoreDashboard) {
            await dashboardIntegration.restoreDashboard();
        }

        dashboardIntegration?.onRowsUpdated?.();
    };

    const runUniversalSearch = async (query) => {
        const trimmedQuery = query.trim();

        if (!searchUrl || trimmedQuery === '') {
            setSearchLoading(false);

            return;
        }

        if (!dashboardIntegration) {
            return;
        }

        searchAbortController?.abort();
        searchAbortController = new AbortController();
        const requestId = ++searchRequestId;

        setSearchLoading(true);
        setDashboardSearchActive(true);

        const params = new URLSearchParams({ q: trimmedQuery });

        try {
            const response = await fetch(`${searchUrl}?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: searchAbortController.signal,
            });

            if (!response.ok || requestId !== searchRequestId) {
                return;
            }

            const data = await response.json();

            if (requestId !== searchRequestId) {
                return;
            }

            const incidentIds = (data.incident_ids ?? []).map(Number);
            const matchCount = data.match_count ?? 0;
            const card = getDashboardCard();

            showSearchBanner(card, { matchCount });

            if (incidentIds.length === 0) {
                dashboardIntegration.onRowsUpdated?.();

                return;
            }

            await applySearchRows(incidentIds, matchCount);
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }
        } finally {
            if (requestId === searchRequestId) {
                setSearchLoading(false);
            }
        }
    };

    const clearSearch = async () => {
        searchAbortController?.abort();
        searchRequestId += 1;
        setSearchLoading(false);

        if (isDashboardSearchActive()) {
            await restoreDashboard();
        }
    };

    getDashboardCard()?.querySelector('[data-dashboard-search-clear]')?.addEventListener('click', () => {
        if (globalInput) {
            globalInput.value = '';
        }

        clearSearch();
    });

    form?.addEventListener('submit', (event) => {
        event.preventDefault();

        const query = globalInput?.value ?? '';

        if (query.trim() === '') {
            clearSearch();

            return;
        }

        runUniversalSearch(query);
    });

    globalInput?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();

        const query = globalInput.value ?? '';

        if (query.trim() === '') {
            clearSearch();

            return;
        }

        runUniversalSearch(query);
    });

    globalInput?.addEventListener('search', () => {
        if ((globalInput.value ?? '').trim() === '') {
            clearSearch();
        }
    });

    globalInput?.addEventListener('input', () => {
        if ((globalInput.value ?? '').trim() === '') {
            clearSearch();
        }
    });
};
