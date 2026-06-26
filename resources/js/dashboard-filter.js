import { getWorkspaceSession } from './workspace/session';

const FILTERED_OUT_CLASS = 'dashboard-case-row--filtered-out';
const EMPTY_ROW_ID = 'dashboard-quick-filter-empty-row';
const DEBOUNCE_MS = 200;

const normalizeQuery = (value) => value.trim().toLowerCase();

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

export const applyDashboardQuickFilter = ({
    card,
    query = '',
    countElement = null,
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

    return { visibleCount, totalCount: rows.length };
};

export const initDashboardQuickFilter = ({
    pageRoot = document,
    onFilterApplied = null,
} = {}) => {
    const card = pageRoot.querySelector('.dashboard-service-cases-card');
    const input = pageRoot.querySelector('[data-dashboard-quick-filter-input]');
    const countElement = pageRoot.querySelector('[data-dashboard-filter-count]');

    if (!card || !input) {
        return null;
    }

    let debounceTimer = null;

    const reapply = () => {
        const result = applyDashboardQuickFilter({
            card,
            query: input.value,
            countElement,
        });

        onFilterApplied?.(result);

        return result;
    };

    const clearFilter = () => {
        input.value = '';
        reapply();
        input.focus();
    };

    input.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(reapply, DEBOUNCE_MS);
    });

    card.addEventListener('click', (event) => {
        if (event.target.closest('[data-dashboard-quick-filter-clear]')) {
            event.preventDefault();
            clearFilter();
        }
    });

    reapply();

    return {
        reapply,
        clearFilter,
        getQuery: () => input.value,
    };
};
