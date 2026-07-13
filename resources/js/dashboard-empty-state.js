export const DASHBOARD_EMPTY_ROW_ID = 'dashboard-service-cases-empty-row';
export const DASHBOARD_QUICK_FILTER_EMPTY_ROW_ID = 'dashboard-quick-filter-empty-row';

export const DASHBOARD_EMPTY_VARIANT = {
    FILTERED: 'filtered',
    CAUGHT_UP: 'caught-up',
};

const escapeHtml = (value) => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');

const buildActionsHtml = ({ showSearchAgain = false, clearAction = 'quick-filter' } = {}) => {
    const clearAttribute = clearAction === 'search'
        ? 'data-dashboard-search-clear'
        : 'data-dashboard-quick-filter-clear';

    const searchAgainButton = showSearchAgain
        ? `<button type="button"
                  class="btn btn-sm btn-outline-secondary dashboard-service-cases-empty-state__action"
                  data-dashboard-empty-search-again>
              Search Again
           </button>`
        : '';

    return `
        <div class="dashboard-service-cases-empty-state__actions">
            <button type="button"
                    class="btn btn-sm btn-primary dashboard-service-cases-empty-state__action"
                    ${clearAttribute}>
                Clear Filters
            </button>
            ${searchAgainButton}
        </div>
    `;
};

export const buildDashboardEmptyStateHtml = ({
    variant = DASHBOARD_EMPTY_VARIANT.FILTERED,
    colSpan = 12,
    rowId = DASHBOARD_EMPTY_ROW_ID,
    showSearchAgain = false,
    clearAction = 'quick-filter',
} = {}) => {
    const isCaughtUp = variant === DASHBOARD_EMPTY_VARIANT.CAUGHT_UP;
    const icon = isCaughtUp ? 'bi-inbox' : 'bi-search';
    const title = isCaughtUp ? 'All caught up!' : 'No service cases found';
    const subtitle = isCaughtUp
        ? 'No service cases require attention right now.'
        : 'Try adjusting your search or filters.';
    const actionsHtml = isCaughtUp ? '' : buildActionsHtml({ showSearchAgain, clearAction });

    return `
        <tr id="${escapeHtml(rowId)}" class="dashboard-service-cases-empty-row">
            <td colspan="${Number(colSpan)}" class="dashboard-service-cases-empty-cell">
                <div class="dashboard-service-cases-empty-state" role="status">
                    <div class="dashboard-service-cases-empty-state__icon" aria-hidden="true">
                        <i class="bi ${icon}"></i>
                    </div>
                    <h3 class="dashboard-service-cases-empty-state__title">${escapeHtml(title)}</h3>
                    <p class="dashboard-service-cases-empty-state__subtitle">${escapeHtml(subtitle)}</p>
                    ${actionsHtml}
                </div>
            </td>
        </tr>
    `;
};

export const getTableColumnCount = (tbody) => {
    const table = tbody?.closest('table');

    return table?.querySelectorAll('thead th').length ?? 12;
};

const getServiceCaseRows = (tbody) => {
    if (!tbody) {
        return [];
    }

    if (tbody.rows) {
        return Array.from(tbody.rows).filter((row) => (row.id ?? '').startsWith('service-case-row-'));
    }

    return Array.from(tbody.querySelectorAll('tr[id^="service-case-row-"]'));
};

export const hasVisibleServiceCaseRows = (tbody) => getServiceCaseRows(tbody)
    .some((row) => !row.classList.contains('dashboard-case-row--filtered-out'));

export const hasActiveEmptyPresentation = (tbody) => {
    if (!tbody) {
        return false;
    }

    const serverEmptyRow = tbody.querySelector(`#${DASHBOARD_EMPTY_ROW_ID}`);
    const quickFilterEmptyRow = tbody.querySelector(`#${DASHBOARD_QUICK_FILTER_EMPTY_ROW_ID}`);

    return Boolean(serverEmptyRow)
        || Boolean(quickFilterEmptyRow && !quickFilterEmptyRow.classList.contains('d-none'));
};

export const syncDashboardTableEmptyPresentation = (card) => {
    const wrap = card?.querySelector('#dashboard-service-cases-scroll');
    const tbody = card?.querySelector('#dashboard-service-cases-body');

    if (!wrap || !tbody) {
        return;
    }

    if (hasVisibleServiceCaseRows(tbody)) {
        wrap.classList.remove('dashboard-cases-table-wrap--empty');

        return;
    }

    wrap.classList.toggle('dashboard-cases-table-wrap--empty', hasActiveEmptyPresentation(tbody));
};
