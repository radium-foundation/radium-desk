const formatServiceCaseCount = (visibleCount, totalCount) => {
    const label = totalCount === 1 ? 'service case' : 'service cases';

    return `Showing ${visibleCount} of ${totalCount} ${label}`;
};

let loadedCount = 0;
let filterTotal = 0;

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
};

export const getServiceCaseLoadedCount = () => loadedCount;

export const getServiceCaseFilterTotal = () => filterTotal;

export const setServiceCasePagination = ({ loaded, total } = {}) => {
    if (loaded !== undefined) {
        loadedCount = loaded;
    }

    if (total !== undefined) {
        filterTotal = total;
    }

    syncCardDataset();
    updateLoadMoreVisibility();
    updateServiceCaseCountDisplay();
};

export const appendServiceCaseLoadedCount = (count) => {
    loadedCount += count;
    syncCardDataset();
    updateLoadMoreVisibility();
    updateServiceCaseCountDisplay();
};

export const updateServiceCaseCountDisplay = ({
    countElement = null,
    visibleCount = null,
} = {}) => {
    const element = countElement ?? document.querySelector('[data-dashboard-filter-count]');

    if (!element) {
        return;
    }

    const visible = visibleCount ?? document.querySelectorAll(
        'tr[id^="service-case-row-"]:not(.dashboard-case-row--filtered-out)',
    ).length;

    element.textContent = formatServiceCaseCount(visible, filterTotal);
};

export { formatServiceCaseCount };
