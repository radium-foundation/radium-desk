import * as bootstrap from 'bootstrap';
import { isDashboardSearchActive, setDashboardSearchActive } from './dashboard-search-mode';
import { hideSearchBanner, showSearchBanner } from './dashboard-search-banner';

const SEARCH_ICON_HTML = '<i class="bi bi-search"></i>';
const SEARCH_LOADING_HTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
const SEARCH_MATCH_CLASS = 'dashboard-case-row--search-match';
const SEARCH_FETCH_ERROR = 'Unable to load search results. Please try again.';
const SEARCH_ROWS_ERROR = 'Unable to load matching service cases. Please try again.';
const INTAKE_FALLBACK_SELECTOR = '[data-dashboard-search-intake-fallback]';

const formatIntakePreviewValue = (value) => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (Array.isArray(value)) {
        return value.join(', ');
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
};

const buildLegacyPreviewSummaryHtml = (preview) => {
    const fields = [
        ['Order ID', preview.order_id],
        ['Customer name', preview.customer_name],
        ['Mobile', preview.mobile],
        ['Product / model', preview.product_model],
        ['Serial number', preview.serial_number],
    ];

    return `
        <dl class="row small mb-0">
            ${fields.map(([label, value]) => `
                <dt class="col-sm-4 text-muted">${label}</dt>
                <dd class="col-sm-8 mb-1">${formatIntakePreviewValue(value)}</dd>
            `).join('')}
        </dl>
    `;
};

const buildIntakeFallbackMessage = (intake, query) => {
    if (intake?.requires_confirmation && intake?.legacy_preview) {
        return intake.legacy_preview_message ?? `Legacy order found for ${query}.`;
    }

    if (intake?.classification === 'new_contact') {
        return `No desk record found for ${query}.`;
    }

    if ((intake?.matches ?? []).length > 0) {
        return `Desk record found for ${query}.`;
    }

    return `No record found for ${query}.`;
};

const prefillAndOpenQuickCreate = (intake, query) => {
    const modalElement = document.getElementById('quickCreateModal');
    const form = modalElement?.querySelector('#customerIntakeForm');

    if (!modalElement || !form) {
        return;
    }

    const parsedQuery = intake?.parsed_query ?? {};
    const phoneField = form.querySelector('#intake_phone');
    const orderField = form.querySelector('#intake_order_id');
    const serialField = form.querySelector('#intake_serial_number');

    if (phoneField) {
        phoneField.value = parsedQuery.phone ?? '';
    }

    if (orderField) {
        orderField.value = parsedQuery.order_id ?? '';
    }

    if (serialField) {
        serialField.value = parsedQuery.serial_number ?? '';
    }

    if (!parsedQuery.phone && !parsedQuery.order_id && !parsedQuery.serial_number && orderField && query) {
        orderField.value = query;
    }

    modalElement.dataset.resetOnShow = 'false';

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    modal.show();

    window.setTimeout(() => {
        modalElement.querySelector('#intake-search-button')?.click();
    }, 0);
};

const buildSearchRowsUrl = (baseUrl, incidentIds) => {
    const params = new URLSearchParams();

    incidentIds.forEach((incidentId) => {
        params.append('ids[]', String(incidentId));
    });

    return `${baseUrl}?${params.toString()}`;
};

const buildDashboardSearchUrl = (dashboardUrl, query) => {
    const params = new URLSearchParams({ q: query.trim() });

    return `${dashboardUrl}?${params.toString()}`;
};

const buildSearchEmptyRowHtml = (card) => {
    const colCount = card?.querySelector('thead tr')?.children.length ?? 12;

    return `
        <tr id="dashboard-service-cases-empty-row">
            <td colspan="${colCount}" class="dashboard-cases-empty text-center text-muted small py-3">
                No matching records.
            </td>
        </tr>
    `;
};

const clearSearchMatchHighlight = (card) => {
    card?.querySelectorAll(`.${SEARCH_MATCH_CLASS}`).forEach((row) => {
        row.classList.remove(SEARCH_MATCH_CLASS);
    });
};

const highlightSearchMatch = (card, incidentId) => {
    clearSearchMatchHighlight(card);

    const row = document.getElementById(`service-case-row-${incidentId}`);

    if (!row) {
        return;
    }

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

export const initUniversalSearch = ({
    dashboardIntegration = null,
} = {}) => {
    const form = document.querySelector('[data-universal-search-form]');
    const globalInput = document.getElementById('global-search-input');
    const searchUrl = form?.dataset.searchUrl ?? '';
    const dashboardUrl = form?.dataset.dashboardUrl ?? '';
    const searchControl = document.querySelector('[data-universal-search-control]');
    const searchIcon = document.querySelector('[data-universal-search-icon]');

    let searchRequestId = 0;
    let searchAbortController = null;

    const setSearchLoading = (loading) => {
        if (!searchIcon) {
            return;
        }

        searchControl?.toggleAttribute('aria-busy', loading);
        searchIcon.innerHTML = loading ? SEARCH_LOADING_HTML : SEARCH_ICON_HTML;
    };

    const getDashboardCard = () => (
        dashboardIntegration?.pageRoot?.querySelector('.dashboard-service-cases-card') ?? null
    );

    const redirectToDashboardSearch = (query) => {
        if (!dashboardUrl) {
            return;
        }

        window.location.assign(buildDashboardSearchUrl(dashboardUrl, query));
    };

    const hideIntakeFallback = (card) => {
        card?.querySelector(INTAKE_FALLBACK_SELECTOR)?.remove();
    };

    const showIntakeFallback = (card, intake, query) => {
        hideIntakeFallback(card);

        const banner = card?.querySelector('[data-dashboard-search-banner]');

        if (!banner || !intake) {
            return;
        }

        const panel = document.createElement('div');
        panel.className = 'dashboard-search-intake-fallback border-top px-3 py-3';
        panel.dataset.dashboardSearchIntakeFallback = '';

        const message = buildIntakeFallbackMessage(intake, query);
        const previewHtml = intake.requires_confirmation && intake.legacy_preview
            ? `<div class="alert alert-info py-2 small mb-3">${buildLegacyPreviewSummaryHtml(intake.legacy_preview)}</div>`
            : '';

        panel.innerHTML = `
            <p class="small text-muted mb-2">${message}</p>
            ${previewHtml}
            <button type="button"
                    class="btn btn-sm btn-primary"
                    data-dashboard-search-intake-action>
                Create Service Request
            </button>
        `;

        panel.querySelector('[data-dashboard-search-intake-action]')?.addEventListener('click', () => {
            prefillAndOpenQuickCreate(intake, query);
        });

        banner.appendChild(panel);
    };

    const showSearchEmptyResults = (card) => {
        if (!dashboardIntegration?.applyRows) {
            return;
        }

        dashboardIntegration.applyRows([], {
            serviceCasesEmpty: true,
            serviceCasesEmptyHtml: buildSearchEmptyRowHtml(card),
        });
        dashboardIntegration.onRowsUpdated?.();
    };

    const showSearchFailure = (card, message) => {
        showSearchBanner(card, { error: message });
        setDashboardSearchActive(true);
    };

    const applySearchRows = async (incidentIds, matchCount, query) => {
        if (!dashboardIntegration?.searchRowsUrl || !dashboardIntegration.applyRows) {
            return false;
        }

        const card = getDashboardCard();

        if (!card || incidentIds.length === 0) {
            return false;
        }

        const rowsResponse = await fetch(
            buildSearchRowsUrl(dashboardIntegration.searchRowsUrl, incidentIds),
            {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: searchAbortController?.signal,
            },
        );

        if (!rowsResponse.ok) {
            showSearchFailure(card, SEARCH_ROWS_ERROR);

            return false;
        }

        const rowsData = await rowsResponse.json();

        dashboardIntegration.applyRows(rowsData.rows ?? [], {
            serviceCasesEmpty: Boolean(rowsData.service_cases_empty),
            serviceCasesEmptyHtml: rowsData.service_cases_empty_html ?? buildSearchEmptyRowHtml(card),
        });
        dashboardIntegration.onRowsUpdated?.();

        if (matchCount === 1 && incidentIds.length === 1) {
            const incidentId = incidentIds[0];
            highlightSearchMatch(card, incidentId);

            const row = document.getElementById(`service-case-row-${incidentId}`);
            const referenceLabel = row?.querySelector('.case-reference-link')?.textContent?.trim() ?? '';

            await dashboardIntegration.openDrawer?.(incidentId, referenceLabel);
        } else {
            clearSearchMatchHighlight(card);
        }

        return true;
    };

    const restoreDashboard = async () => {
        setDashboardSearchActive(false);
        hideIntakeFallback(getDashboardCard());
        hideSearchBanner(getDashboardCard());
        clearSearchMatchHighlight(getDashboardCard());
        dashboardIntegration?.closeDrawer?.();

        if (dashboardIntegration?.restoreDashboard) {
            await dashboardIntegration.restoreDashboard();
        }

        dashboardIntegration?.onRowsUpdated?.();
    };

    const runUniversalSearch = async (query) => {
        const trimmedQuery = query.trim();

        if (trimmedQuery === '') {
            setSearchLoading(false);

            return;
        }

        if (!dashboardIntegration) {
            redirectToDashboardSearch(trimmedQuery);

            return;
        }

        if (!searchUrl) {
            setSearchLoading(false);

            return;
        }

        searchAbortController?.abort();
        searchAbortController = new AbortController();
        const requestId = ++searchRequestId;

        setSearchLoading(true);
        setDashboardSearchActive(true);

        const params = new URLSearchParams({ q: trimmedQuery });

        try {
            const response = await fetch(`${searchUrl}?${params.toString()}`, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: searchAbortController.signal,
            });

            if (requestId !== searchRequestId) {
                return;
            }

            const card = getDashboardCard();

            if (!response.ok) {
                showSearchFailure(card, SEARCH_FETCH_ERROR);

                return;
            }

            const data = await response.json();

            if (requestId !== searchRequestId) {
                return;
            }

            const incidentIds = (data.incident_ids ?? []).map(Number);
            const matchCount = data.match_count ?? 0;
            const intake = data.intake ?? null;

            if (incidentIds.length === 0) {
                showSearchEmptyResults(card);

                if (intake) {
                    showSearchBanner(card, { matchCount: 0, query: trimmedQuery });
                    showIntakeFallback(card, intake, trimmedQuery);
                } else {
                    showSearchBanner(card, { matchCount, query: trimmedQuery });
                }

                dashboardIntegration.onRowsUpdated?.();

                return;
            }

            hideIntakeFallback(card);
            showSearchBanner(card, { matchCount, query: trimmedQuery });

            await applySearchRows(incidentIds, matchCount, trimmedQuery);
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }

            if (requestId === searchRequestId) {
                showSearchFailure(getDashboardCard(), SEARCH_FETCH_ERROR);
            }
        } finally {
            if (requestId === searchRequestId) {
                setSearchLoading(false);
            }
        }
    };

    const clearSearch = async () => {
        searchAbortController?.abort();
        searchRequestId += 1;
        setSearchLoading(false);

        if (isDashboardSearchActive()) {
            await restoreDashboard();
        }
    };

    getDashboardCard()?.querySelector('[data-dashboard-search-clear]')?.addEventListener('click', () => {
        if (globalInput) {
            globalInput.value = '';
        }

        clearSearch();
    });

    form?.addEventListener('submit', (event) => {
        event.preventDefault();

        const query = globalInput?.value ?? '';

        if (query.trim() === '') {
            clearSearch();

            return;
        }

        runUniversalSearch(query);
    });

    globalInput?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();

        const query = globalInput.value ?? '';

        if (query.trim() === '') {
            clearSearch();

            return;
        }

        runUniversalSearch(query);
    });

    globalInput?.addEventListener('search', () => {
        if ((globalInput.value ?? '').trim() === '') {
            clearSearch();
        }
    });

    globalInput?.addEventListener('input', () => {
        if ((globalInput.value ?? '').trim() === '') {
            clearSearch();
        }
    });

    const pendingQuery = new URLSearchParams(window.location.search).get('q')?.trim() ?? '';

    if (pendingQuery !== '' && globalInput) {
        globalInput.value = pendingQuery;

        if (dashboardIntegration) {
            runUniversalSearch(pendingQuery);
        }
    }
};
