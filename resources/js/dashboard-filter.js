import { getWorkspaceSession } from './workspace/session';
import {
    getServiceCaseFilterTotal,
    updateServiceCaseCountDisplay,
    formatServiceCaseCount,
} from './dashboard-service-case-state';

const FILTERED_OUT_CLASS = 'dashboard-case-row--filtered-out';
const SEARCH_MATCH_CLASS = 'dashboard-case-row--search-match';
const EMPTY_ROW_ID = 'dashboard-quick-filter-empty-row';
const LOCAL_DEBOUNCE_MS = 150;

const normalizeQuery = (value) => value.trim().toLowerCase();

const tokenizeQuery = (value) => normalizeQuery(value).split(/\s+/).filter(Boolean);

const rowMatchesQuery = (searchText, normalizedQuery) => {
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

    return rowMatchesQuery(searchText, normalizedQuery);
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

const updateCounter = (countElement, visibleCount) => {
    if (!countElement) {
        return;
    }

    countElement.textContent = formatServiceCaseCount(visibleCount, getServiceCaseFilterTotal());
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

    updateCounter(countElement, visibleCount);
    updateEmptyRow(tbody, normalizedQuery !== '' && rows.length > 0 && visibleCount === 0);

    if (! skipHighlight) {
        if (normalizedQuery === '') {
            clearSearchMatchHighlight(card);
        } else {
            highlightSingleMatch(card);
        }
    }

    return { visibleCount, totalCount: getServiceCaseFilterTotal() };
};

export const initDashboardQuickFilter = ({
    pageRoot = document,
    onFilterApplied = null,
} = {}) => {
    const card = pageRoot.querySelector('.dashboard-service-cases-card');
    const container = pageRoot.querySelector('[data-dashboard-quick-filter]');
    const input = pageRoot.querySelector('[data-dashboard-quick-filter-input]');
    const countElement = pageRoot.querySelector('[data-dashboard-filter-count]');
    const trigger = pageRoot.querySelector('[data-dashboard-quick-filter-trigger]');
    const control = pageRoot.querySelector('[data-dashboard-quick-filter-control]');

    if (!card || !input || !container) {
        return null;
    }

    let debounceTimer = null;

    const isExpanded = () => container.classList.contains('dashboard-quick-filter--expanded');

    const openQuickFilter = () => {
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
        if (!isExpanded()) {
            return;
        }

        container.classList.remove('dashboard-quick-filter--expanded');
        trigger?.setAttribute('aria-expanded', 'false');
        control?.classList.add('d-none');
    };

    const reapply = ({ immediate = false } = {}) => {
        const run = () => {
            const result = applyDashboardQuickFilter({
                card,
                query: input.value,
                countElement,
            });

            onFilterApplied?.(result);

            return result;
        };

        clearTimeout(debounceTimer);

        if (immediate) {
            return run();
        }

        debounceTimer = setTimeout(run, LOCAL_DEBOUNCE_MS);
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

    document.addEventListener('mousedown', (event) => {
        if (!isExpanded() || container.contains(event.target)) {
            return;
        }

        closeQuickFilter();
    });

    card.addEventListener('click', (event) => {
        if (event.target.closest('[data-dashboard-quick-filter-clear]')) {
            event.preventDefault();
            clearFilter();
        }
    });

    reapply({ immediate: true });

    return {
        reapply: () => reapply({ immediate: true }),
        clearFilter,
        getQuery: () => input.value,
        open: openQuickFilter,
        close: closeQuickFilter,
        isExpanded,
    };
};
