import { applyRows } from './live-dashboard';
import { syncGlobalSearchInput } from './universal-search';

const SEARCH_MATCH_CLASS = 'dashboard-case-row--search-match';
const UNIVERSAL_SEARCH_DEBOUNCE_MS = 400;
const MIN_TEXT_SEARCH_LENGTH = 2;
const SEARCH_ICON_HTML = '<i class="bi bi-search"></i>';
const SEARCH_LOADING_HTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';

export const SEARCH_INTENT = {
    STRUCTURED: 'structured',
    TEXT: 'text',
};

export const detectSearchIntent = (query) => {
    const trimmed = query.trim();

    if (trimmed === '') {
        return null;
    }

    if (/^\d+$/.test(trimmed)) {
        return SEARCH_INTENT.STRUCTURED;
    }

    if (/^RD/i.test(trimmed) || /^R$/i.test(trimmed)) {
        return SEARCH_INTENT.STRUCTURED;
    }

    if (/^S(?:C(?:[- ]?\d*)?)?$/i.test(trimmed)) {
        return SEARCH_INTENT.STRUCTURED;
    }

    if (/^(?:SCN|SN|TXN|REF)/i.test(trimmed)) {
        return SEARCH_INTENT.STRUCTURED;
    }

    if (/^SC[A-Z]/i.test(trimmed)) {
        return SEARCH_INTENT.STRUCTURED;
    }

    if (trimmed.includes('@') || /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]*$/.test(trimmed)) {
        return SEARCH_INTENT.TEXT;
    }

    if (/^[A-Z0-9][A-Z0-9._-]*$/i.test(trimmed) && ! /\s/.test(trimmed) && (/\d/.test(trimmed) || /[-_.]/.test(trimmed))) {
        return SEARCH_INTENT.STRUCTURED;
    }

    if (/^[a-zA-Z][a-zA-Z\s'.-]*$/.test(trimmed)) {
        return SEARCH_INTENT.TEXT;
    }

    return SEARCH_INTENT.TEXT;
};

export const shouldRunUniversalSearch = (query) => {
    const trimmed = query.trim();

    if (trimmed === '') {
        return false;
    }

    const intent = detectSearchIntent(trimmed);

    if (intent === SEARCH_INTENT.STRUCTURED) {
        return true;
    }

    return trimmed.length >= MIN_TEXT_SEARCH_LENGTH;
};

export const isUniversalSearchActive = () => universalSearchActive;

export const resetDashboardQuickFilterState = () => {
    universalSearchActive = false;
    universalSearchAbortController = null;
    lastUniversalSearchQuery = '';
    restoreDashboardRows = null;
};

let universalSearchActive = false;
let universalSearchAbortController = null;
let lastUniversalSearchQuery = '';
let restoreDashboardRows = null;

const getDataRows = (tbody) => Array.from(
    tbody.querySelectorAll('tr[id^="service-case-row-"]'),
);

const getTableColumnCount = (tbody) => {
    const table = tbody.closest('table');

    return table?.querySelectorAll('thead th').length ?? 1;
};

const updateCounter = (countElement, visibleCount, totalCount) => {
    if (!countElement) {
        return;
    }

    countElement.textContent = `${visibleCount} / ${totalCount}`;
};

const clearSearchMatchHighlight = (card) => {
    card?.querySelectorAll(`.${SEARCH_MATCH_CLASS}`).forEach((row) => {
        row.classList.remove(SEARCH_MATCH_CLASS);
    });
};

const highlightSingleMatch = (card) => {
    const tbody = card?.querySelector('#dashboard-service-cases-body');

    if (!tbody) {
        return;
    }

    clearSearchMatchHighlight(card);

    const visibleRows = getDataRows(tbody);

    if (visibleRows.length !== 1) {
        return;
    }

    const row = visibleRows[0];
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

export const applyDashboardQuickFilter = ({
    card,
    countElement = null,
    skipHighlight = false,
} = {}) => {
    const tbody = card?.querySelector('#dashboard-service-cases-body');

    if (!tbody) {
        return { visibleCount: 0, totalCount: 0 };
    }

    const rows = getDataRows(tbody);
    const rowCount = rows.length;

    updateCounter(countElement, rowCount, rowCount);

    if (! skipHighlight) {
        clearSearchMatchHighlight(card);
    }

    return { visibleCount: rowCount, totalCount: rowCount };
};

export const initDashboardQuickFilter = ({
    pageRoot = document,
    onFilterApplied = null,
    onUniversalSearchStateChange = null,
} = {}) => {
    const card = pageRoot.querySelector('.dashboard-service-cases-card');
    const input = pageRoot.querySelector('[data-dashboard-quick-filter-input]');
    const countElement = pageRoot.querySelector('[data-dashboard-filter-count]');
    const searchControl = pageRoot.querySelector('.dashboard-quick-filter__control');
    const searchIcon = pageRoot.querySelector('.dashboard-quick-filter__icon');
    const searchUrl = pageRoot?.dataset.searchUrl ?? '';

    if (!card || !input) {
        return null;
    }

    let universalDebounceTimer = null;
    let searchRequestId = 0;

    const setSearchLoading = (loading) => {
        if (!searchIcon) {
            return;
        }

        searchControl?.toggleAttribute('aria-busy', loading);
        searchIcon.innerHTML = loading ? SEARCH_LOADING_HTML : SEARCH_ICON_HTML;
    };

    const setUniversalSearchActive = (active) => {
        if (universalSearchActive === active) {
            return;
        }

        universalSearchActive = active;
        pageRoot.dataset.universalSearchActive = active ? 'true' : 'false';
        onUniversalSearchStateChange?.(active);
    };

    const reapply = () => {
        if (universalSearchActive) {
            return { visibleCount: 0, totalCount: 0 };
        }

        const result = applyDashboardQuickFilter({
            card,
            countElement,
        });

        onFilterApplied?.(result);

        return result;
    };

    const restoreFilterView = async () => {
        if (typeof restoreDashboardRows === 'function') {
            await restoreDashboardRows();
        }
    };

    const runUniversalSearch = async (query, { force = false } = {}) => {
        const trimmedQuery = query.trim();

        if (! searchUrl || trimmedQuery === '') {
            setSearchLoading(false);

            if (universalSearchActive) {
                setUniversalSearchActive(false);
                lastUniversalSearchQuery = '';
                await restoreFilterView();
            }

            reapply();

            return;
        }

        if (! force && ! shouldRunUniversalSearch(trimmedQuery)) {
            setSearchLoading(false);

            if (universalSearchActive) {
                setUniversalSearchActive(false);
                lastUniversalSearchQuery = '';
                await restoreFilterView();
            }

            reapply();

            return;
        }

        if (trimmedQuery === lastUniversalSearchQuery) {
            setSearchLoading(false);

            return;
        }

        universalSearchAbortController?.abort();
        universalSearchAbortController = new AbortController();
        const requestId = ++searchRequestId;

        setSearchLoading(true);

        const params = new URLSearchParams({ q: trimmedQuery });
        const view = pageRoot.dataset.liveView;
        const filter = pageRoot.dataset.liveFilter;

        if (view && view !== 'all') {
            params.set('view', view);
        }

        if (filter) {
            params.set('filter', filter);
        }

        try {
            const response = await fetch(`${searchUrl}?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: universalSearchAbortController.signal,
            });

            if (! response.ok || requestId !== searchRequestId) {
                return;
            }

            const data = await response.json();

            if (requestId !== searchRequestId) {
                return;
            }

            lastUniversalSearchQuery = trimmedQuery;
            setUniversalSearchActive(true);

            applyRows(data.rows ?? [], {
                serviceCasesEmpty: (data.match_count ?? 0) === 0,
                serviceCasesEmptyHtml: `
                    <tr id="dashboard-service-cases-empty-row">
                        <td colspan="${getTableColumnCount(card.querySelector('#dashboard-service-cases-body'))}" class="dashboard-cases-empty">
                            No service cases match this search.
                        </td>
                    </tr>
                `,
            });

            clearSearchMatchHighlight(card);

            const matchCount = data.match_count ?? 0;
            updateCounter(countElement, matchCount, matchCount);

            if (matchCount === 1) {
                highlightSingleMatch(card);
            }

            onFilterApplied?.({ visibleCount: matchCount, totalCount: matchCount });
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

    const scheduleSearch = ({ immediate = false, force = false } = {}) => {
        syncGlobalSearchInput(input.value);

        clearTimeout(universalDebounceTimer);

        const query = input.value;

        if (query.trim() === '') {
            universalSearchAbortController?.abort();
            searchRequestId += 1;
            lastUniversalSearchQuery = '';
            setSearchLoading(false);

            if (universalSearchActive) {
                setUniversalSearchActive(false);
                restoreFilterView().then(() => {
                    reapply();
                });
            } else {
                reapply();
            }

            return;
        }

        if (! force && ! shouldRunUniversalSearch(query)) {
            universalSearchAbortController?.abort();
            searchRequestId += 1;
            lastUniversalSearchQuery = '';
            setSearchLoading(false);

            if (universalSearchActive) {
                setUniversalSearchActive(false);
                restoreFilterView().then(() => {
                    reapply();
                });
            }

            return;
        }

        setSearchLoading(true);

        if (immediate) {
            runUniversalSearch(query, { force });

            return;
        }

        universalDebounceTimer = setTimeout(() => {
            runUniversalSearch(query);
        }, UNIVERSAL_SEARCH_DEBOUNCE_MS);
    };

    const clearFilter = () => {
        input.value = '';
        scheduleSearch();
        input.focus();
    };

    input.addEventListener('input', () => {
        scheduleSearch();
    });

    input.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();
        scheduleSearch({ immediate: true, force: true });
    });

    if (bootstrapFromUrl() && shouldRunUniversalSearch(input.value)) {
        scheduleSearch({ immediate: true });
    } else {
        reapply();
    }

    return {
        reapply,
        clearFilter,
        getQuery: () => input.value,
        isUniversalSearchActive,
        setQuery: (query, { runSearch = false } = {}) => {
            input.value = query;
            syncGlobalSearchInput(query);

            if (runSearch) {
                scheduleSearch({ immediate: true });
            }
        },
        setRestoreHandler: (handler) => {
            restoreDashboardRows = handler;
        },
    };
};

const bootstrapFromUrl = () => {
    const pageRoot = document.getElementById('dashboard-page');
    const input = pageRoot?.querySelector('[data-dashboard-quick-filter-input]');

    if (!input) {
        return false;
    }

    const urlQuery = new URLSearchParams(window.location.search).get('q') ?? '';

    if (urlQuery === '') {
        return false;
    }

    input.value = urlQuery;
    syncGlobalSearchInput(urlQuery);

    return true;
};
