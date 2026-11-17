import { initTooltips } from './tooltips';
import { buildHardwareRowsUrl, parsePositiveInt } from './hardware-dashboard-progressive';
import {
    clearHardwarePageCache,
    readHardwarePageCache,
    writeHardwarePageCache,
} from './hardware-dashboard-page-cache';

let rowsAbortController = null;
let rowsInFlight = false;
let rowsRequestGeneration = 0;

const formatCount = (value) => `(${Math.max(0, Number(value) || 0)})`;

const parseChunkSizes = (workspace) => ({
    first: parsePositiveInt(workspace.dataset.hardwareFirstChunk, 4),
    followUp: parsePositiveInt(workspace.dataset.hardwareRowChunk, 3),
});

const fetchRowsPayload = async (workspace, options, signal) => {
    const response = await fetch(buildHardwareRowsUrl(workspace, options), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal,
    });
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return response.json();
};

const applyRowsPayload = (workspace, tbody, paginationHost, payload, { appendRows = false } = {}) => {
    if (tbody && typeof payload.rows_html === 'string') {
        if (appendRows) {
            tbody.insertAdjacentHTML('beforeend', payload.rows_html);
        } else {
            tbody.innerHTML = payload.rows_html;
        }
    }
    if (paginationHost && typeof payload.pagination_html === 'string' && !appendRows) {
        paginationHost.innerHTML = payload.pagination_html;
    }

    if (payload.page !== undefined) {
        workspace.dataset.hardwarePage = String(payload.page);
    }

    workspace.dataset.hardwareInspectedCount = String(payload.inspected_fulfilment_count ?? '');
    workspace.dataset.hardwareRowsLoaded = '1';
    updateCounts(workspace, payload.scope_counts ?? {}, payload.filter_counts ?? {});
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

const setChunkLoading = (workspace, show) => {
    const tableWrap = workspace.querySelector('.dashboard-cases-table-wrap');
    tableWrap?.classList.toggle('dashboard-hardware-table-wrap--chunk-loading', show);
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
            clearHardwarePageCache(workspace);
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
        clearHardwarePageCache(workspace);
        workspace.dataset.hardwareSearch = input.value.trim();
        workspace.dataset.hardwarePage = '1';
        syncLocationQuery(workspace);
        void loadRows({ page: '1', searchChanged: true });
    });
};

const prefetchHardwarePage = (workspace, page) => {
  if (readHardwarePageCache(workspace, page)) {
        return;
    }

    void fetchRowsPayload(workspace, {
        page,
        origin: window.location.origin,
    }).then((payload) => {
        writeHardwarePageCache(workspace, page, payload);
    }).catch(() => {
        // Best-effort prefetch only.
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

    const loadChunkedPage = async (requestGeneration, page, { skipCountsAfterFirst = true } = {}) => {
        const { first, followUp } = parseChunkSizes(workspace);
        let offset = 0;
        let limit = first;
        let mergedPayload = null;
        let isFirstChunk = true;

        while (true) {
            const payload = await fetchRowsPayload(workspace, {
                page,
                chunkOffset: offset,
                chunkLimit: limit,
                skipCounts: skipCountsAfterFirst && !isFirstChunk,
                origin: window.location.origin,
            }, rowsAbortController.signal);

            if (requestGeneration !== rowsRequestGeneration) {
                return null;
            }

            if (isFirstChunk) {
                tableWrap?.classList.remove('dashboard-hardware-table-wrap--loading');
                applyRowsPayload(workspace, tbody, paginationHost, payload);
            } else {
                applyRowsPayload(workspace, tbody, paginationHost, payload, { appendRows: true });
            }

            mergedPayload = isFirstChunk
                ? { ...payload }
                : {
                    ...mergedPayload,
                    inspected_fulfilment_count: payload.inspected_fulfilment_count,
                    chunk: payload.chunk,
                };

            isFirstChunk = false;

            const chunk = payload.chunk ?? {};
            if (chunk.complete !== false) {
                break;
            }

            offset = Number(chunk.next_offset ?? offset + limit);
            limit = followUp;
            setChunkLoading(workspace, true);
        }

        setChunkLoading(workspace, false);
        if (mergedPayload && tbody) {
            mergedPayload.rows_html = tbody.innerHTML;
        }

        return mergedPayload;
    };

    const loadRows = async ({ page, subsequent = false, searchChanged = false } = {}) => {
        const requestGeneration = ++rowsRequestGeneration;
        const pageNum = String(page ?? workspace.dataset.hardwarePage ?? '1');

        rowsAbortController?.abort();
        rowsAbortController = new AbortController();
        rowsInFlight = true;
        showError(workspace, '', false);

        const cached = !searchChanged ? readHardwarePageCache(workspace, pageNum) : null;
        if (cached) {
            applyRowsPayload(workspace, tbody, paginationHost, cached.payload);
            tableWrap?.classList.remove('dashboard-hardware-table-wrap--loading');
            setPageLoading(workspace, false);
            initTooltips(workspace);
            bindPagination(workspace, loadRows);
        } else if (subsequent) {
            setPageLoading(workspace, true);
            setStatus(workspace, 'Loading next hardware queue page.');
        } else if (searchChanged) {
            tableWrap?.classList.add('dashboard-hardware-table-wrap--loading');
            setStatus(workspace, 'Searching hardware queue.');
        } else if (workspace.dataset.hardwareRowsLoaded !== '1') {
            setStatus(workspace, 'Loading hardware queue rows.');
        }

        try {
            const useChunked = workspace.dataset.hardwareProgressive === '1' && !cached;
            let payload = cached?.payload ?? null;

            if (!payload) {
                payload = useChunked
                    ? await loadChunkedPage(requestGeneration, pageNum)
                    : await fetchRowsPayload(workspace, { page: pageNum, origin: window.location.origin }, rowsAbortController.signal);
            }

            if (requestGeneration !== rowsRequestGeneration || !payload) {
                return;
            }

            if (!cached && !useChunked) {
                tableWrap?.classList.remove('dashboard-hardware-table-wrap--loading');
                applyRowsPayload(workspace, tbody, paginationHost, payload);
            }

            writeHardwarePageCache(workspace, pageNum, payload);
            bindPagination(workspace, loadRows);

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
                setStatus(workspace, `Loaded page ${payload.page ?? pageNum} of ${payload.last_page ?? 1} (${total} matching orders).`);
            }

            const nextPage = parsePositiveInt(String(Number(pageNum) + 1), 0);
            if (nextPage > 1 && payload.last_page && nextPage <= payload.last_page) {
                prefetchHardwarePage(workspace, String(nextPage));
            }
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }
            if (requestGeneration !== rowsRequestGeneration) {
                return;
            }
            if (cached) {
                setStatus(workspace, 'Showing cached hardware page; refresh failed.');
            } else {
                tableWrap?.classList.remove('dashboard-hardware-table-wrap--loading');
                showError(workspace, 'Hardware queue rows could not be loaded. Please retry.', true);
                setStatus(workspace, 'Hardware queue failed to load.');
            }
        } finally {
            tableWrap?.classList.remove('dashboard-hardware-table-wrap--loading');
            setPageLoading(workspace, false);
            setChunkLoading(workspace, false);
            rowsInFlight = false;
        }
    };

    workspace.querySelector('[data-hardware-rows-retry]')?.addEventListener('click', () => {
        clearHardwarePageCache(workspace);
        delete workspace.dataset.hardwareRowsLoaded;
        void loadRows();
    });

    bindPagination(workspace, loadRows);
    bindDebouncedSearch(workspace, loadRows);

    void loadRows();
    void loadShiprocketBalance(workspace);
};