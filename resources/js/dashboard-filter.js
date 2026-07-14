import { mergeServiceCaseRows } from './live-dashboard-merge';
import { initTooltips } from './tooltips';
import { getWorkspaceSession } from './workspace/session';
import {
    getServiceCaseFilterTotal,
    getServiceCaseLoadedCount,
    getServiceCaseSearchQuery,
    isDashboardQuickFilterActive,
    setServiceCasePagination,
    setServiceCaseSearchQuery,
    updateServiceCaseCountDisplay,
    formatServiceCaseCount,
} from './dashboard-service-case-state';
import { buildDashboardLiveQuery } from './dashboard-live-query';
import {
    buildDashboardEmptyStateHtml,
    DASHBOARD_EMPTY_VARIANT,
    DASHBOARD_QUICK_FILTER_EMPTY_ROW_ID,
    getTableColumnCount,
    syncDashboardTableEmptyPresentation,
} from './dashboard-empty-state';

const FILTERED_OUT_CLASS = 'dashboard-case-row--filtered-out';
const SEARCH_MATCH_CLASS = 'dashboard-case-row--search-match';
const EMPTY_ROW_ID = DASHBOARD_QUICK_FILTER_EMPTY_ROW_ID;
const LOCAL_DEBOUNCE_MS = 150;
const SERVER_DEBOUNCE_MS = 250;

export const normalizeQuery = (value) => value.trim().toLowerCase();

export const tokenizeQuery = (value) => normalizeQuery(value).split(/\s+/).filter(Boolean);

export const rowMatchesQuery = (searchText, normalizedQuery) => {
    if (!normalizedQuery) {
        return true;
    }

    const tokens = tokenizeQuery(normalizedQuery);

    if (tokens.length === 0) {
        return true;
    }

    return tokens.every((token) => searchText.includes(token));
};

const getDataRows = (tbody) => Array.from(
    tbody.querySelectorAll('tr[id^="service-case-row-"]'),
);

export const rowShouldStayVisible = (row, normalizedQuery, lockedIncidentIds) => {
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

    return rowMatchesQuery(searchText, normalizedQuery);
};

const getTableColumnCountFromTbody = (tbody) => getTableColumnCount(tbody);

const ensureEmptyRow = (tbody, { showSearchAgain = true } = {}) => {
    let emptyRow = document.getElementById(EMPTY_ROW_ID);

    if (emptyRow) {
        return emptyRow;
    }

    const wrapper = document.createElement('tbody');
    wrapper.innerHTML = buildDashboardEmptyStateHtml({
        variant: DASHBOARD_EMPTY_VARIANT.FILTERED,
        colSpan: getTableColumnCountFromTbody(tbody),
        rowId: EMPTY_ROW_ID,
        showSearchAgain,
    });
    emptyRow = wrapper.firstElementChild;
    emptyRow.classList.add('d-none');
    tbody.appendChild(emptyRow);

    return emptyRow;
};

const updateEmptyRow = (tbody, show, options = {}) => {
    const card = tbody?.closest('.dashboard-service-cases-card');

    if (getDataRows(tbody).length === 0) {
        document.getElementById(EMPTY_ROW_ID)?.classList.add('d-none');
        syncDashboardTableEmptyPresentation(card);

        return;
    }

    const emptyRow = ensureEmptyRow(tbody, options);
    const cell = emptyRow.querySelector('td');

    if (cell) {
        cell.colSpan = getTableColumnCountFromTbody(tbody);
    }

    emptyRow.classList.toggle('d-none', ! show);
    syncDashboardTableEmptyPresentation(card);
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

const clearLocalFilterState = (card) => {
    const tbody = card?.querySelector('#dashboard-service-cases-body');

    getDataRows(tbody).forEach((row) => {
        row.classList.remove(FILTERED_OUT_CLASS);
    });

    updateEmptyRow(tbody, false);
    clearSearchMatchHighlight(card);
};

const applyLocalQuickFilterPresentation = ({
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

    if (countElement) {
        countElement.textContent = formatServiceCaseCount(visibleCount, getServiceCaseFilterTotal());
    } else {
        updateServiceCaseCountDisplay({ visibleCount });
    }

    updateEmptyRow(tbody, normalizedQuery !== '' && rows.length > 0 && visibleCount === 0, {
        showSearchAgain: normalizedQuery !== '',
    });

    if (! skipHighlight) {
        if (normalizedQuery === '') {
            clearSearchMatchHighlight(card);
        } else {
            highlightSingleMatch(card);
        }
    }

    return { visibleCount, totalCount: getServiceCaseFilterTotal() };
};

export const applyDashboardQuickFilter = applyLocalQuickFilterPresentation;

export const initDashboardQuickFilter = ({
    pageRoot = document,
    onFilterApplied = null,
    loadMoreUrl = null,
    onRestoreDashboard = null,
} = {}) => {
    const card = pageRoot.querySelector('.dashboard-service-cases-card');
    const container = pageRoot.querySelector('[data-dashboard-quick-filter]');
    const input = pageRoot.querySelector('[data-dashboard-quick-filter-input]');
    const countElement = pageRoot.querySelector('[data-dashboard-filter-count]');
    const trigger = pageRoot.querySelector('[data-dashboard-quick-filter-trigger]');
    const control = pageRoot.querySelector('[data-dashboard-quick-filter-control]');
    const resolvedLoadMoreUrl = loadMoreUrl ?? pageRoot.dataset?.dashboardLoadMoreUrl ?? '';

    if (!card || !input || !container) {
        return null;
    }

    let debounceTimer = null;
    let searchRequestId = 0;
    let searchAbortController = null;
    const alwaysOpen = container.dataset.dashboardQuickFilterAlwaysOpen === 'true';

    const isExpanded = () => alwaysOpen || container.classList.contains('dashboard-quick-filter--expanded');

    const openQuickFilter = () => {
        if (alwaysOpen) {
            input.focus();
            input.select();

            return;
        }

        if (isExpanded()) {
            input.focus();
            input.select();

            return;
        }

        container.classList.add('dashboard-quick-filter--expanded');
        trigger?.setAttribute('aria-expanded', 'true');
        control?.classList.remove('d-none');

        requestAnimationFrame(() => {
            input.focus();

            if (input.value !== '') {
                input.select();
            }
        });
    };

    const closeQuickFilter = () => {
        if (alwaysOpen || !isExpanded()) {
            return;
        }

        container.classList.remove('dashboard-quick-filter--expanded');
        trigger?.setAttribute('aria-expanded', 'false');
        control?.classList.add('d-none');
    };

    const applyServerSearchResults = (data) => {
        const rows = data.rows ?? [];

        mergeServiceCaseRows(
            card,
            rows,
            Boolean(data.service_cases_empty),
            data.service_cases_empty_html ?? '',
            initTooltips,
            {
                lockedIncidentIds: getWorkspaceSession().getLockedIncidentIds(),
                onRowsUpdated: () => {},
            },
        );

        clearLocalFilterState(card);

        setServiceCasePagination({
            loaded: data.loaded_count ?? rows.length,
            total: data.total_count ?? rows.length,
        });

        updateEmptyRow(
            card.querySelector('#dashboard-service-cases-body'),
            rows.length === 0,
        );

        highlightSingleMatch(card);

        const result = {
            visibleCount: data.loaded_count ?? rows.length,
            totalCount: data.total_count ?? rows.length,
        };

        onFilterApplied?.(result);

        return result;
    };

    const fetchServerQuickFilter = async (query) => {
        if (!resolvedLoadMoreUrl) {
            return applyLocalQuickFilterPresentation({ card, query, countElement });
        }

        const normalizedQuery = normalizeQuery(query);
        const requestId = ++searchRequestId;

        searchAbortController?.abort();
        searchAbortController = new AbortController();

        setServiceCaseSearchQuery(normalizedQuery);

        const params = buildDashboardLiveQuery(pageRoot, {
            fallbackQueue: card.dataset.serviceCaseFilter ?? 'action_required',
            offset: 0,
            q: normalizedQuery,
        });

        try {
            const response = await fetch(`${resolvedLoadMoreUrl}?${params.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: searchAbortController.signal,
            });

            if (requestId !== searchRequestId || !response.ok) {
                return null;
            }

            return applyServerSearchResults(await response.json());
        } catch (error) {
            if (error?.name === 'AbortError') {
                return null;
            }

            return null;
        }
    };

    const restoreDashboard = async () => {
        setServiceCaseSearchQuery('');
        clearLocalFilterState(card);
        document.getElementById(EMPTY_ROW_ID)?.classList.add('d-none');

        if (onRestoreDashboard) {
            await onRestoreDashboard();
        }

        const result = {
            visibleCount: getServiceCaseLoadedCount(),
            totalCount: getServiceCaseFilterTotal(),
        };

        updateServiceCaseCountDisplay({ countElement, visibleCount: result.visibleCount });
        onFilterApplied?.(result);

        return result;
    };

    const reapply = ({ immediate = false, skipFetch = false } = {}) => {
        const query = input.value;
        const normalizedQuery = normalizeQuery(query);

        const run = () => {
            if (normalizedQuery === '') {
                if (isDashboardQuickFilterActive()) {
                    return restoreDashboard();
                }

                const emptyResult = applyLocalQuickFilterPresentation({ card, query, countElement });
                onFilterApplied?.(emptyResult);

                return emptyResult;
            }

            if (skipFetch && normalizedQuery === getServiceCaseSearchQuery()) {
                const result = {
                    visibleCount: getServiceCaseLoadedCount(),
                    totalCount: getServiceCaseFilterTotal(),
                };

                updateServiceCaseCountDisplay({ countElement, visibleCount: result.visibleCount });
                highlightSingleMatch(card);
                onFilterApplied?.(result);

                return result;
            }

            return fetchServerQuickFilter(query);
        };

        clearTimeout(debounceTimer);

        if (immediate) {
            return run();
        }

        debounceTimer = setTimeout(run, normalizedQuery === '' ? LOCAL_DEBOUNCE_MS : SERVER_DEBOUNCE_MS);
    };

    const clearFilter = () => {
        input.value = '';
        reapply({ immediate: true });
        openQuickFilter();
    };

    input.addEventListener('input', () => {
        reapply();
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();

            if (alwaysOpen) {
                input.blur();

                return;
            }

            closeQuickFilter();

            return;
        }

        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();
        reapply({ immediate: true });
    });

    input.addEventListener('blur', () => {
        if (alwaysOpen) {
            return;
        }

        window.setTimeout(() => {
            if (!isExpanded() || container.contains(document.activeElement)) {
                return;
            }

            if (input.value.trim() === '') {
                closeQuickFilter();
            }
        }, 0);
    });

    trigger?.addEventListener('click', (event) => {
        event.preventDefault();
        openQuickFilter();
    });

    if (alwaysOpen) {
        container.classList.add('dashboard-quick-filter--expanded');
        control?.classList.remove('d-none');
    }

    document.addEventListener('mousedown', (event) => {
        if (alwaysOpen || !isExpanded() || container.contains(event.target)) {
            return;
        }

        closeQuickFilter();
    });

    card.addEventListener('click', (event) => {
        if (event.target.closest('[data-dashboard-quick-filter-clear]')) {
            event.preventDefault();
            clearFilter();
        }

        if (event.target.closest('[data-dashboard-empty-search-again]')) {
            event.preventDefault();
            openQuickFilter();
        }
    });

    reapply({ immediate: true });

    return {
        reapply: () => reapply({ immediate: true, skipFetch: true }),
        clearFilter,
        getQuery: () => input.value,
        open: openQuickFilter,
        close: closeQuickFilter,
        isExpanded,
    };
};
