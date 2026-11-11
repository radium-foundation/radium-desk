import { initTooltips } from './tooltips';

let rowsAbortController = null;
let rowsInFlight = false;
let rowsRequestGeneration = 0;

const formatCount = (value) => `(${Math.max(0, Number(value) || 0)})`;

const parsePositiveInt = (value, fallback) => {
    const parsed = Number.parseInt(String(value ?? ''), 10);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
};

const buildRowsUrl = (workspace, { page } = {}) => {
    const url = new URL(workspace.dataset.hardwareRowsUrl, window.location.origin);
    const params = new URLSearchParams(window.location.search);

    const scope = workspace.dataset.hardwareScope || 'active';
    const filter = workspace.dataset.hardwareFilter || 'needs_action';
    const pageNum = page ?? workspace.dataset.hardwarePage ?? '1';
    const search = workspace.dataset.hardwareSearch
        || params.get('q')
        || '';

    url.searchParams.set('hw_scope', scope);
    url.searchParams.set('hw_filter', filter);
    url.searchParams.set('hw_page', String(pageNum));

    if (workspace.dataset.hardwareProgressive === '1') {
        url.searchParams.set('hw_progressive', '1');
    }

    if (search !== '') {
        url.searchParams.set('q', search);
    }

    return url;
};

const syncLocationQuery = (workspace) => {
    const url = new URL(window.location.href);
    const search = workspace.dataset.hardwareSearch || '';
    const page = workspace.dataset.hardwarePage || '1';

    if (search === '') {
        url.searchParams.delete('q');
    } else {
        url.searchParams.set('q', search);
    }

    if (page === '1') {
        url.searchParams.delete('hw_page');
    } else {
        url.searchParams.set('hw_page', page);
    }

    window.history.replaceState({}, '', url);
};

const updateCounts = (root, scopeCounts = {}, filterCounts = {}) => {
    root.querySelectorAll('[data-hardware-filter-count]').forEach((el) => {
        const key = el.getAttribute('data-hardware-filter-count');
        if (key && Object.prototype.hasOwnProperty.call(filterCounts, key)) {
            el.textContent = formatCount(filterCounts[key]);
        }
    });
    root.querySelectorAll('[data-hardware-scope-count]').forEach((el) => {
        const key = el.getAttribute('data-hardware-scope-count');
        if (key === 'active' && scopeCounts.active !== undefined) {
            el.textContent = formatCount(scopeCounts.active);
        }
    });
};

const setStatus = (workspace, message) => {
    const status = workspace.querySelector('[data-hardware-rows-status]');
    if (status) {
        status.textContent = message;
    }
};

const showError = (workspace, message, show) => {
    const error = workspace.querySelector('[data-hardware-rows-error]');
    const errorText = workspace.querySelector('[data-hardware-rows-error-text]');
    if (!error) {
        return;
    }
    if (show) {
        error.classList.remove('d-none');
        error.hidden = false;
        if (errorText) {
            errorText.textContent = message;
        }
    } else {
        error.classList.add('d-none');
        error.hidden = true;
    }
};

const setPageLoading = (workspace, show) => {
    const indicator = workspace.querySelector('[data-hardware-page-loading]');
    if (!indicator) {
        return;
    }
    if (show) {
        indicator.classList.remove('d-none');
        indicator.hidden = false;
    } else {
        indicator.classList.add('d-none');
        indicator.hidden = true;
    }
};

const loadShiprocketBalance = async (workspace) => {
    const shiprocketUrl = workspace.dataset.hardwareShiprocketUrl;
    const card = workspace.closest('.dashboard-service-cases-card');
    const balance = card?.querySelector('[data-shiprocket-balance]');
    if (!shiprocketUrl || !balance) {
        return;
    }

    try {
        const response = await fetch(shiprocketUrl, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) {
            return;
        }
        const payload = await response.json();
        if (typeof payload.html !== 'string') {
            return;
        }
        balance.outerHTML = payload.html;
    } catch {
        // Informational widget only.
    }
};

const bindPagination = (workspace, loadRows) => {
    workspace.querySelectorAll('[data-hardware-pagination-link]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const targetPage = link.getAttribute('data-hardware-page-target');
            if (!targetPage) {
                return;
            }
            workspace.dataset.hardwarePage = targetPage;
            syncLocationQuery(workspace);
            void loadRows({ page: targetPage, subsequent: true });
        });
    });
};

const bindDebouncedSearch = (workspace, loadRows) => {
    const filterRoot = document.querySelector('[data-hardware-quick-filter]');
    const input = filterRoot?.querySelector('[data-hardware-quick-filter-input]');
    const form = input?.closest('form');
    if (!input) {
        return;
    }

    const debounceMs = parsePositiveInt(workspace.dataset.hardwareSearchDebounceMs, 300);
    let debounceTimer = null;

    const scheduleSearch = () => {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(() => {
            const nextSearch = input.value.trim();
            if (nextSearch === (workspace.dataset.hardwareSearch || '')) {
                return;
            }
            workspace.dataset.hardwareSearch = nextSearch;
            workspace.dataset.hardwarePage = '1';
            syncLocationQuery(workspace);
            void loadRows({ page: '1', searchChanged: true });
        }, debounceMs);
    };

    input.addEventListener('input', scheduleSearch);
    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        window.clearTimeout(debounceTimer);
        workspace.dataset.hardwareSearch = input.value.trim();
        workspace.dataset.hardwarePage = '1';
        syncLocationQuery(workspace);
        void loadRows({ page: '1', searchChanged: true });
    });
};

export const initHardwareDashboardAsync = (root = document) => {
    const workspace = root.querySelector('[data-hardware-workspace]');
    if (!workspace || workspace.dataset.hardwareDeferRows !== '1') {
        return;
    }

    const tbody = workspace.querySelector('#dashboard-hardware-body');
    const paginationHost = workspace.querySelector('[data-hardware-pagination-host]');
    const tableWrap = workspace.querySelector('.dashboard-cases-table-wrap');
    const selectAll = workspace.querySelector('[data-hardware-select-all]');

    const loadRows = async ({ page, subsequent = false, searchChanged = false } = {}) => {
        const requestGeneration = ++rowsRequestGeneration;

        rowsAbortController?.abort();
        rowsAbortController = new AbortController();
        rowsInFlight = true;
        showError(workspace, '', false);

        const isInitial = !subsequent && !searchChanged && parsePositiveInt(page ?? workspace.dataset.hardwarePage, 1) === 1
            && !workspace.dataset.hardwareRowsLoaded;
        if (subsequent) {
            setPageLoading(workspace, true);
            setStatus(workspace, 'Loading next hardware queue page.');
        } else if (searchChanged) {
            tableWrap?.classList.add('dashboard-hardware-table-wrap--loading');
            setStatus(workspace, 'Searching hardware queue.');
        } else if (!isInitial) {
            tableWrap?.classList.add('dashboard-hardware-table-wrap--loading');
            setStatus(workspace, 'Loading hardware queue rows.');
        } else {
            setStatus(workspace, 'Loading hardware queue rows.');
        }

        try {
            const response = await fetch(
                buildRowsUrl(workspace, {
                    page: page ?? workspace.dataset.hardwarePage,
                }),
                {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: rowsAbortController.signal,
                },
            );

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const payload = await response.json();
            if (requestGeneration !== rowsRequestGeneration) {
                return;
            }

            if (tbody && typeof payload.rows_html === 'string') {
                tbody.innerHTML = payload.rows_html;
            }
            if (paginationHost) {
                paginationHost.innerHTML = typeof payload.pagination_html === 'string'
                    ? payload.pagination_html
                    : '';
                bindPagination(workspace, loadRows);
            }

            if (payload.page !== undefined) {
                workspace.dataset.hardwarePage = String(payload.page);
            }

            workspace.dataset.hardwareInspectedCount = String(payload.inspected_fulfilment_count ?? '');
            workspace.dataset.hardwareRowsLoaded = '1';
            updateCounts(workspace, payload.scope_counts ?? {}, payload.filter_counts ?? {});

            tableWrap?.classList.remove('dashboard-hardware-table-wrap--loading');
            setPageLoading(workspace, false);
            if (selectAll) {
                selectAll.disabled = false;
            }

            initTooltips(workspace);
            workspace.dispatchEvent(new CustomEvent('hardware:rows-loaded', { bubbles: true, detail: payload }));
            syncLocationQuery(workspace);

            const total = payload.unfiltered_total ?? 0;
            if (total === 0) {
                setStatus(workspace, 'No hardware orders match the current filters.');
            } else {
                setStatus(workspace, `Loaded page ${payload.page ?? 1} of ${payload.last_page ?? 1} (${total} matching orders).`);
            }
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }
            if (requestGeneration !== rowsRequestGeneration) {
                return;
            }
            tableWrap?.classList.remove('dashboard-hardware-table-wrap--loading');
            setPageLoading(workspace, false);
            showError(workspace, 'Hardware queue rows could not be loaded. Please retry.', true);
            setStatus(workspace, 'Hardware queue failed to load.');
        } finally {
            rowsInFlight = false;
        }
    };

    workspace.querySelector('[data-hardware-rows-retry]')?.addEventListener('click', () => {
        delete workspace.dataset.hardwareRowsLoaded;
        void loadRows();
    });

    bindPagination(workspace, loadRows);
    bindDebouncedSearch(workspace, loadRows);

    void loadRows();
    void loadShiprocketBalance(workspace);
};
