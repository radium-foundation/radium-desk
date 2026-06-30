import { applyRows } from './live-dashboard';
import { getWorkspaceSession } from './workspace/session';
import { syncGlobalSearchInput } from './universal-search';

const FILTERED_OUT_CLASS = 'dashboard-case-row--filtered-out';
const SEARCH_MATCH_CLASS = 'dashboard-case-row--search-match';
const EMPTY_ROW_ID = 'dashboard-quick-filter-empty-row';
const LOCAL_DEBOUNCE_MS = 150;
const UNIVERSAL_SEARCH_DEBOUNCE_MS = 300;
const MIN_TEXT_SEARCH_LENGTH = 2;

export const SEARCH_INTENT = {
    STRUCTURED: 'structured',
    TEXT: 'text',
};

const normalizeQuery = (value) => value.trim().toLowerCase();

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

let universalSearchActive = false;
let universalSearchAbortController = null;
let lastUniversalSearchQuery = '';
let restoreDashboardRows = null;

const getDataRows = (tbody) => Array.from(
    tbody.querySelectorAll('tr[id^="service-case-row-"]'),
);

const rowShouldStayVisible = (row, normalizedQuery, lockedIncidentIds) => {
    if (!normalizedQuery) {
        return true;
    }

    const incidentId = Number(row.dataset.incidentId);

    if (lockedIncidentIds.includes(incidentId)) {
        return true;
    }

    if (row.querySelector('.transaction-inline-editor:not(.d-none)')) {
        return true;
    }

    const searchText = row.dataset.searchText ?? '';

    return searchText.includes(normalizedQuery);
};

const getTableColumnCount = (tbody) => {
    const table = tbody.closest('table');

    return table?.querySelectorAll('thead th').length ?? 1;
};

const ensureEmptyRow = (tbody) => {
    let emptyRow = document.getElementById(EMPTY_ROW_ID);

    if (emptyRow) {
        return emptyRow;
    }

    emptyRow = document.createElement('tr');
    emptyRow.id = EMPTY_ROW_ID;
    emptyRow.className = 'dashboard-quick-filter-empty-row d-none';

    const cell = document.createElement('td');
    cell.colSpan = getTableColumnCount(tbody);
    cell.className = 'text-center text-muted small py-2';
    cell.innerHTML = `
        No matching rows.
        <button type="button" class="btn btn-link btn-sm p-0 align-baseline dashboard-quick-filter__clear" data-dashboard-quick-filter-clear>
            Clear filter
        </button>
        to view all service cases.
    `;

    emptyRow.appendChild(cell);
    tbody.appendChild(emptyRow);

    return emptyRow;
};

const updateEmptyRow = (tbody, show) => {
    if (getDataRows(tbody).length === 0) {
        document.getElementById(EMPTY_ROW_ID)?.classList.add('d-none');

        return;
    }

    const emptyRow = ensureEmptyRow(tbody);
    const cell = emptyRow.querySelector('td');

    if (cell) {
        cell.colSpan = getTableColumnCount(tbody);
    }

    emptyRow.classList.toggle('d-none', ! show);
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

    const visibleRows = getDataRows(tbody).filter(
        (row) => ! row.classList.contains(FILTERED_OUT_CLASS),
    );

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
    query = '',
    countElement = null,
    skipHighlight = false,
} = {}) => {
    const tbody = card?.querySelector('#dashboard-service-cases-body');

    if (!tbody) {
        return { visibleCount: 0, totalCount: 0 };
    }

    const normalizedQuery = normalizeQuery(query);
    const lockedIncidentIds = getWorkspaceSession().getLockedIncidentIds();
    const rows = getDataRows(tbody);
    let visibleCount = 0;

    rows.forEach((row) => {
        const isVisible = rowShouldStayVisible(row, normalizedQuery, lockedIncidentIds);

        row.classList.toggle(FILTERED_OUT_CLASS, ! isVisible);

        if (isVisible) {
            visibleCount += 1;
        }
    });

    updateCounter(countElement, visibleCount, rows.length);
    updateEmptyRow(tbody, normalizedQuery !== '' && rows.length > 0 && visibleCount === 0);

    if (! skipHighlight) {
        if (normalizedQuery === '') {
            clearSearchMatchHighlight(card);
        } else {
            highlightSingleMatch(card);
        }
    }

    return { visibleCount, totalCount: rows.length };
};

export const initDashboardQuickFilter = ({
    pageRoot = document,
    onFilterApplied = null,
    onUniversalSearchStateChange = null,
} = {}) => {
    const card = pageRoot.querySelector('.dashboard-service-cases-card');
    const input = pageRoot.querySelector('[data-dashboard-quick-filter-input]');
    const countElement = pageRoot.querySelector('[data-dashboard-filter-count]');
    const searchUrl = pageRoot?.dataset.searchUrl ?? '';

    if (!card || !input) {
        return null;
    }

    let localDebounceTimer = null;
    let universalDebounceTimer = null;
    let searchRequestId = 0;

    const setUniversalSearchActive = (active) => {
        if (universalSearchActive === active) {
            return;
        }

        universalSearchActive = active;
        pageRoot.dataset.universalSearchActive = active ? 'true' : 'false';
        onUniversalSearchStateChange?.(active);
    };

    const reapply = () => {
        const result = applyDashboardQuickFilter({
            card,
            query: input.value,
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

    const runUniversalSearch = async (query) => {
        const trimmedQuery = query.trim();

        if (! searchUrl || ! shouldRunUniversalSearch(trimmedQuery)) {
            if (universalSearchActive) {
                setUniversalSearchActive(false);
                lastUniversalSearchQuery = '';
                await restoreFilterView();
            }

            reapply();

            return;
        }

        if (trimmedQuery === lastUniversalSearchQuery) {
            return;
        }

        universalSearchAbortController?.abort();
        universalSearchAbortController = new AbortController();
        const requestId = ++searchRequestId;

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
            updateEmptyRow(card.querySelector('#dashboard-service-cases-body'), false);

            if (matchCount === 1) {
                highlightSingleMatch(card);
            }

            onFilterApplied?.({ visibleCount: matchCount, totalCount: matchCount });
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }
        }
    };

    const scheduleSearch = () => {
        syncGlobalSearchInput(input.value);

        clearTimeout(localDebounceTimer);
        clearTimeout(universalDebounceTimer);

        const query = input.value;
        const normalizedQuery = normalizeQuery(query);

        localDebounceTimer = setTimeout(() => {
            if (! universalSearchActive && ! shouldRunUniversalSearch(query)) {
                reapply();
            }
        }, LOCAL_DEBOUNCE_MS);

        if (normalizedQuery === '') {
            universalSearchAbortController?.abort();
            searchRequestId += 1;
            lastUniversalSearchQuery = '';

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

        if (! shouldRunUniversalSearch(query)) {
            if (universalSearchActive) {
                setUniversalSearchActive(false);
                restoreFilterView().then(() => {
                    reapply();
                });
            }

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

    input.addEventListener('input', scheduleSearch);

    card.addEventListener('click', (event) => {
        if (event.target.closest('[data-dashboard-quick-filter-clear]')) {
            event.preventDefault();
            clearFilter();
        }
    });

    const bootstrapFromUrl = () => {
        const urlQuery = new URLSearchParams(window.location.search).get('q') ?? '';

        if (urlQuery === '') {
            return false;
        }

        input.value = urlQuery;
        syncGlobalSearchInput(urlQuery);

        return true;
    };

    if (bootstrapFromUrl() && shouldRunUniversalSearch(input.value)) {
        scheduleSearch();
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
                scheduleSearch();
            }
        },
        setRestoreHandler: (handler) => {
            restoreDashboardRows = handler;
        },
    };
};
