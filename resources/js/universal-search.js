import { applyRows } from './live-dashboard';

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

export const resetUniversalSearchState = () => {
    universalSearchActive = false;
    universalSearchAbortController = null;
    lastUniversalSearchQuery = '';
    restoreDashboardRows = null;
};

let universalSearchActive = false;
let universalSearchAbortController = null;
let lastUniversalSearchQuery = '';
let restoreDashboardRows = null;

const getTableColumnCount = () => {
    const tbody = document.querySelector('#dashboard-service-cases-body');
    const table = tbody?.closest('table');

    return table?.querySelectorAll('thead th').length ?? 1;
};

const buildDashboardSearchUrl = (query) => {
    const url = new URL('/dashboard', window.location.origin);

    if (query.trim() !== '') {
        url.searchParams.set('q', query.trim());
    }

    return url.toString();
};

const syncGlobalSearchInput = (query) => {
    const globalInput = document.getElementById('global-search-input');

    if (globalInput instanceof HTMLInputElement && globalInput.value !== query) {
        globalInput.value = query;
    }
};

export const initUniversalSearch = ({
    pageRoot = null,
    refreshDashboard = null,
} = {}) => {
    const form = document.querySelector('[data-universal-search-form]');
    const globalInput = document.getElementById('global-search-input');
    const dashboardPage = pageRoot?.id === 'dashboard-page'
        ? pageRoot
        : document.getElementById('dashboard-page');
    const searchUrl = dashboardPage?.dataset.searchUrl ?? '';
    const searchControl = document.querySelector('[data-universal-search-control]');
    const searchIcon = document.querySelector('[data-universal-search-icon]');

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
        if (!dashboardPage) {
            return;
        }

        if (universalSearchActive === active) {
            return;
        }

        universalSearchActive = active;
        dashboardPage.dataset.universalSearchActive = active ? 'true' : 'false';
    };

    const restoreFilterView = async () => {
        if (typeof restoreDashboardRows === 'function') {
            await restoreDashboardRows();
        } else if (typeof refreshDashboard === 'function') {
            await refreshDashboard();
        }
    };

    const runUniversalSearch = async (query, { force = false } = {}) => {
        const trimmedQuery = query.trim();

        if (!searchUrl || trimmedQuery === '') {
            setSearchLoading(false);

            if (universalSearchActive) {
                setUniversalSearchActive(false);
                lastUniversalSearchQuery = '';
                await restoreFilterView();
            }

            return;
        }

        if (!force && !shouldRunUniversalSearch(trimmedQuery)) {
            setSearchLoading(false);

            if (universalSearchActive) {
                setUniversalSearchActive(false);
                lastUniversalSearchQuery = '';
                await restoreFilterView();
            }

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
        const view = dashboardPage?.dataset.liveView;
        const filter = dashboardPage?.dataset.liveFilter;

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

            if (!response.ok || requestId !== searchRequestId) {
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
                        <td colspan="${getTableColumnCount()}" class="dashboard-cases-empty">
                            No service cases match this search.
                        </td>
                    </tr>
                `,
            });
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

    const scheduleSearch = (query, { immediate = false, force = false } = {}) => {
        syncGlobalSearchInput(query);
        clearTimeout(universalDebounceTimer);

        if (query.trim() === '') {
            universalSearchAbortController?.abort();
            searchRequestId += 1;
            lastUniversalSearchQuery = '';
            setSearchLoading(false);

            if (universalSearchActive) {
                setUniversalSearchActive(false);
                restoreFilterView();
            }

            return;
        }

        if (!force && !shouldRunUniversalSearch(query)) {
            universalSearchAbortController?.abort();
            searchRequestId += 1;
            lastUniversalSearchQuery = '';
            setSearchLoading(false);

            if (universalSearchActive) {
                setUniversalSearchActive(false);
                restoreFilterView();
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

    const applySearch = (query, { immediate = true, force = true } = {}) => {
        if (!dashboardPage) {
            if (query.trim() === '') {
                window.location.href = buildDashboardSearchUrl('');

                return;
            }

            window.location.href = buildDashboardSearchUrl(query);

            return;
        }

        scheduleSearch(query, { immediate, force });
    };

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        applySearch(globalInput?.value ?? '', { immediate: true, force: true });
    });

    globalInput?.addEventListener('input', () => {
        scheduleSearch(globalInput.value);
    });

    globalInput?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();
        scheduleSearch(globalInput.value, { immediate: true, force: true });
    });

    const urlQuery = new URLSearchParams(window.location.search).get('q') ?? '';

    if (dashboardPage && urlQuery !== '') {
        syncGlobalSearchInput(urlQuery);

        if (shouldRunUniversalSearch(urlQuery)) {
            scheduleSearch(urlQuery, { immediate: true });
        }
    }

    return {
        applySearch,
        syncGlobalSearchInput,
        isUniversalSearchActive,
        setRestoreHandler: (handler) => {
            restoreDashboardRows = handler;
        },
    };
};

export { syncGlobalSearchInput };
