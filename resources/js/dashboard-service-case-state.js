const formatServiceCaseCount = (visibleCount, totalCount) => `${visibleCount} of ${totalCount} Showing`;

let loadedCount = 0;
let filterTotal = 0;
let searchQuery = '';

const getCard = () => document.querySelector('.dashboard-service-cases-card');

const updateLoadMoreVisibility = () => {
    const wrap = document.querySelector('[data-dashboard-load-more-wrap]');

    if (!wrap) {
        return;
    }

    wrap.classList.toggle('d-none', loadedCount >= filterTotal);
};

const syncCardDataset = () => {
    const card = getCard();

    if (!card) {
        return;
    }

    card.dataset.serviceCasesLoaded = String(loadedCount);
    card.dataset.serviceCaseFilterTotal = String(filterTotal);
};

export const initServiceCasePaginationState = (pageRoot = document) => {
    const card = pageRoot.querySelector('.dashboard-service-cases-card');

    loadedCount = Number(card?.dataset.serviceCasesLoaded ?? 0);
    filterTotal = Number(card?.dataset.serviceCaseFilterTotal ?? loadedCount);
    searchQuery = '';

    updateLoadMoreVisibility();
    updateFilterCountVisibility();
};

export const getServiceCaseLoadedCount = () => loadedCount;

export const getServiceCaseFilterTotal = () => filterTotal;

export const getServiceCaseSearchQuery = () => searchQuery;

export const isDashboardQuickFilterActive = () => searchQuery.trim() !== '';

export const setServiceCaseSearchQuery = (query = '') => {
    searchQuery = query.trim();
};

export const setServiceCasePagination = ({ loaded, total } = {}) => {
    if (loaded !== undefined) {
        loadedCount = loaded;
    }

    if (total !== undefined) {
        filterTotal = total;
    }

    syncCardDataset();
    updateLoadMoreVisibility();
    updateFilterCountVisibility();
    updateServiceCaseCountDisplay();
};

export const appendServiceCaseLoadedCount = (count) => {
    loadedCount += count;
    syncCardDataset();
    updateLoadMoreVisibility();
    updateFilterCountVisibility();
    updateServiceCaseCountDisplay();
};

const updateFilterCountVisibility = () => {
    const wrap = document.querySelector('[data-dashboard-filter-count-wrap]');
    const card = getCard();

    if (!wrap || card?.dataset.agentCompactLayout !== 'true') {
        return;
    }

    const hasMore = loadedCount < filterTotal;
    const filterActive = isDashboardQuickFilterActive();
    const showCount = hasMore || filterActive;

    wrap.classList.toggle('dashboard-quick-filter__summary--icon', ! showCount);
    wrap.classList.toggle('d-none', false);

    const countElement = wrap.querySelector('[data-dashboard-filter-count]');

    if (countElement) {
        countElement.classList.toggle('d-none', ! showCount);
    }
};

export const updateServiceCaseCountDisplay = ({
    countElement = null,
    visibleCount = null,
} = {}) => {
    const element = countElement ?? document.querySelector('[data-dashboard-filter-count]');

    if (!element) {
        return;
    }

    const visible = visibleCount ?? (
        isDashboardQuickFilterActive()
            ? loadedCount
            : document.querySelectorAll(
                'tr[id^="service-case-row-"]:not(.dashboard-case-row--filtered-out)',
            ).length
    );

    element.textContent = formatServiceCaseCount(visible, filterTotal);
    updateFilterCountVisibility();
};

export { formatServiceCaseCount };
