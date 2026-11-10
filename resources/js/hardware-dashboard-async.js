import { initTooltips } from './tooltips';

let rowsAbortController = null;
let rowsInFlight = false;

const formatCount = (value) => `(${Math.max(0, Number(value) || 0)})`;

const buildRowsUrl = (workspace) => {
    const url = new URL(workspace.dataset.hardwareRowsUrl, window.location.origin);
    const params = new URLSearchParams(window.location.search);

    url.searchParams.set('hw_scope', workspace.dataset.hardwareScope || 'active');
    url.searchParams.set('hw_filter', workspace.dataset.hardwareFilter || 'needs_action');
    url.searchParams.set('hw_page', workspace.dataset.hardwarePage || '1');

    const search = workspace.dataset.hardwareSearch
        || params.get('q')
        || '';
    if (search !== '') {
        url.searchParams.set('q', search);
    }

    return url;
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

export const initHardwareDashboardAsync = (root = document) => {
    const workspace = root.querySelector('[data-hardware-workspace]');
    if (!workspace || workspace.dataset.hardwareDeferRows !== '1') {
        return;
    }

    const tbody = workspace.querySelector('#dashboard-hardware-body');
    const paginationHost = workspace.querySelector('[data-hardware-pagination-host]');
    const tableWrap = workspace.querySelector('.dashboard-cases-table-wrap');
    const selectAll = workspace.querySelector('[data-hardware-select-all]');

    const loadRows = async () => {
        if (rowsInFlight) {
            return;
        }

        rowsAbortController?.abort();
        rowsAbortController = new AbortController();
        rowsInFlight = true;
        showError(workspace, '', false);
        setStatus(workspace, 'Loading hardware queue rows.');

        try {
            const response = await fetch(buildRowsUrl(workspace), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: rowsAbortController.signal,
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const payload = await response.json();
            if (tbody && typeof payload.rows_html === 'string') {
                tbody.innerHTML = payload.rows_html;
            }
            if (paginationHost) {
                paginationHost.innerHTML = typeof payload.pagination_html === 'string'
                    ? payload.pagination_html
                    : '';
            }

            workspace.dataset.hardwareInspectedCount = String(payload.inspected_fulfilment_count ?? '');
            updateCounts(workspace, payload.scope_counts ?? {}, payload.filter_counts ?? {});

            tableWrap?.classList.remove('dashboard-hardware-table-wrap--loading');
            if (selectAll) {
                selectAll.disabled = false;
            }

            initTooltips(workspace);
            workspace.dispatchEvent(new CustomEvent('hardware:rows-loaded', { bubbles: true, detail: payload }));
            setStatus(workspace, payload.unfiltered_total === 0
                ? 'No hardware orders in this queue.'
                : `Loaded ${payload.unfiltered_total ?? 0} matching hardware orders.`);
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }
            showError(workspace, 'Hardware queue rows could not be loaded. Please retry.', true);
            setStatus(workspace, 'Hardware queue failed to load.');
        } finally {
            rowsInFlight = false;
        }
    };

    workspace.querySelector('[data-hardware-rows-retry]')?.addEventListener('click', () => {
        void loadRows();
    });

    void loadRows();
    void loadShiprocketBalance(workspace);
};
