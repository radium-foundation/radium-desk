const syncGlobalSearchInput = (query) => {
    const globalInput = document.getElementById('global-search-input');

    if (globalInput instanceof HTMLInputElement && globalInput.value !== query) {
        globalInput.value = query;
    }
};

const buildDashboardSearchUrl = (query) => {
    const url = new URL('/dashboard', window.location.origin);

    if (query.trim() !== '') {
        url.searchParams.set('q', query.trim());
    }

    return url.toString();
};

export const initUniversalSearch = ({
    getDashboardQuickFilter = null,
} = {}) => {
    const form = document.querySelector('[data-universal-search-form]');
    const globalInput = document.getElementById('global-search-input');
    const isDashboardPage = () => Boolean(document.getElementById('dashboard-page'));

    const applySearch = (query) => {
        const quickFilter = getDashboardQuickFilter?.();

        if (isDashboardPage() && quickFilter) {
            quickFilter.setQuery(query, { runSearch: true });

            return;
        }

        if (query.trim() === '') {
            window.location.href = buildDashboardSearchUrl('');

            return;
        }

        window.location.href = buildDashboardSearchUrl(query);
    };

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        applySearch(globalInput?.value ?? '');
    });

    return {
        applySearch,
        syncGlobalSearchInput,
    };
};

export { syncGlobalSearchInput };
