import * as bootstrap from 'bootstrap';
import { advanceQuickCreateToNewContact, resetQuickCreateStepForDashboardFlow } from './customer-intake';
import { isDashboardSearchActive, setDashboardSearchActive } from './dashboard-search-mode';
import { hideSearchBanner, showSearchBanner } from './dashboard-search-banner';
import {
    buildDashboardEmptyStateHtml,
    DASHBOARD_EMPTY_ROW_ID,
    DASHBOARD_EMPTY_VARIANT,
    getTableColumnCount,
    syncDashboardTableEmptyPresentation,
} from './dashboard-empty-state';
import {
    buildLegacyPreviewSummaryHtml,
    initLegacySearchConfirmModal,
    isLegacyOneClickEligible,
    openLegacySearchConfirmModal,
} from './intake-search-flow';
import { handleHttpAuthStatus } from './core/session-lifecycle';
import { renderIntakeOrderHistory } from './intake-order-history';
const SEARCH_ICON_HTML = '<i class="bi bi-search"></i>';
const SEARCH_LOADING_HTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
const SEARCH_MATCH_CLASS = 'dashboard-case-row--search-match';
const SEARCH_FETCH_ERROR = 'Unable to load search results. Please try again.';
const SEARCH_ROWS_ERROR = 'Unable to load matching service cases. Please try again.';
const INTAKE_FALLBACK_SELECTOR = '[data-dashboard-search-intake-fallback]';
const HISTORICAL_FALLBACK_SELECTOR = '[data-dashboard-search-historical-fallback]';
const SEARCH_RESULT_ACTIONS_SELECTOR = '[data-dashboard-search-result-actions]';
const SEARCH_ORDER_HISTORY_PANEL_SELECTOR = '[data-dashboard-search-order-history-panel]';

const prefillIntakeSearchFields = (form, parsedQuery = {}, query = '') => {
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

    if (
        !parsedQuery.phone
        && !parsedQuery.order_id
        && !parsedQuery.serial_number
        && !parsedQuery.email
        && orderField
        && query.trim() !== ''
    ) {
        orderField.value = query.trim();
    }
};

const shouldAutoRunIntakeSearch = (parsedQuery = {}) => (
    Boolean(parsedQuery.phone || parsedQuery.order_id || parsedQuery.serial_number)
);

const clearStaleQuickCreateState = (form) => {
    const matchedOrderField = form.querySelector('#intake_matched_order_id');
    const legacyOrderField = form.querySelector('#intake_legacy_order_id');

    if (matchedOrderField) {
        matchedOrderField.value = '';
    }

    if (legacyOrderField) {
        legacyOrderField.value = '';
    }
};

const prefillAndOpenQuickCreate = (intake, query) => {
    const modalElement = document.getElementById('quickCreateModal');
    const form = modalElement?.querySelector('#customerIntakeForm');

    if (!modalElement || !form) {
        return;
    }

    const parsedQuery = intake?.parsed_query ?? {};
    clearStaleQuickCreateState(form);
    prefillIntakeSearchFields(form, parsedQuery, query);
    resetQuickCreateStepForDashboardFlow(modalElement, form);

    modalElement.dataset.resetOnShow = 'false';

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    modal.show();

    const feedback = modalElement.querySelector('#intake-search-feedback');

    if (parsedQuery.email) {
        if (feedback) {
            feedback.className = 'alert alert-info mt-3 mb-0 py-2 small';
            feedback.textContent = `Email searches are not supported in Quick Create. Enter a phone number, order ID, or serial number. (${parsedQuery.email})`;
            feedback.classList.remove('d-none');
        }

        return;
    }

    if (feedback) {
        feedback.classList.add('d-none');
        feedback.textContent = '';
    }

    if (intake?.classification === 'new_contact' && shouldAutoRunIntakeSearch(parsedQuery)) {
        window.setTimeout(() => {
            advanceQuickCreateToNewContact(modalElement, form, parsedQuery);
        }, 0);

        return;
    }

    if (!shouldAutoRunIntakeSearch(parsedQuery)) {
        return;
    }

    window.setTimeout(() => {
        modalElement.querySelector('#intake-search-button')?.click();
    }, 0);
};

const refreshCustomer360 = (incidentId) => {
    if (!incidentId) {
        return;
    }

    document.dispatchEvent(new CustomEvent('customer360:refresh', {
        detail: { incidentId },
    }));
};

const openCustomer360FromSearch = (actions, dashboardIntegration) => {
    if (!actions?.incident_id) {
        return;
    }

    const incidentId = actions.incident_id;
    const referenceLabel = actions.display_reference ?? '';
    const historicalInvoice = typeof actions.historical_invoice === 'string' && actions.historical_invoice !== ''
        ? actions.historical_invoice
        : null;

    if (dashboardIntegration?.openDrawer) {
        if (historicalInvoice) {
            dashboardIntegration.openDrawer(incidentId, referenceLabel, { historicalInvoice });
        } else {
            dashboardIntegration.openDrawer(incidentId, referenceLabel);
        }

        return;
    }

    document.dispatchEvent(new CustomEvent('customer360:open', {
        detail: {
            incidentId,
            referenceLabel,
            ...(historicalInvoice ? { historicalInvoice } : {}),
        },
    }));
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
    const tbody = card?.querySelector('#dashboard-service-cases-body');

    return buildDashboardEmptyStateHtml({
        variant: DASHBOARD_EMPTY_VARIANT.FILTERED,
        colSpan: getTableColumnCount(tbody),
        rowId: DASHBOARD_EMPTY_ROW_ID,
        showSearchAgain: true,
        clearAction: 'search',
    });
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
    showToast = null,
} = {}) => {
    const form = document.querySelector('[data-universal-search-form]');
    const globalInput = document.getElementById('global-search-input');
    const searchUrl = form?.dataset.searchUrl ?? '';
    const dashboardUrl = form?.dataset.dashboardUrl ?? '';
    const searchControl = document.querySelector('[data-universal-search-control]');
    const searchIcon = document.querySelector('[data-universal-search-icon]');

    let searchRequestId = 0;
    let searchAbortController = null;
    let lastSearchQuery = '';
    let suppressNextAutoOpen = false;

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

    const hideHistoricalFallback = (card) => {
        card?.querySelector(HISTORICAL_FALLBACK_SELECTOR)?.remove();
    };

    const showHistoricalFallback = (card, historical) => {
        hideHistoricalFallback(card);

        const banner = card?.querySelector('[data-dashboard-search-banner]');

        if (!banner || !historical?.url) {
            return;
        }

        const panel = document.createElement('div');
        panel.className = 'dashboard-search-historical-fallback border-top px-3 py-2';
        panel.dataset.dashboardSearchHistoricalFallback = '';
        panel.innerHTML = `
            <a class="btn btn-sm btn-outline-primary"
               href="${historical.url}"
               data-dashboard-search-historical-action>
                Open historical reprint
            </a>
        `;

        banner.appendChild(panel);
    };

    const hideSearchResultActions = (card) => {
        card?.querySelector(SEARCH_RESULT_ACTIONS_SELECTOR)?.remove();
    };

    const hideSearchOrderHistoryPanel = (card) => {
        card?.querySelector(SEARCH_ORDER_HISTORY_PANEL_SELECTOR)?.remove();
    };

    const showIntakeFallback = (card, intake, query) => {
        hideIntakeFallback(card);

        const banner = card?.querySelector('[data-dashboard-search-banner]');

        if (!banner || !intake) {
            return;
        }

        const panel = document.createElement('div');
        panel.className = 'dashboard-search-intake-fallback border-top px-3 py-2';
        panel.dataset.dashboardSearchIntakeFallback = '';

        const previewHtml = intake.requires_confirmation && intake.legacy_preview
            ? `
                <div class="dashboard-legacy-preview-card">
                    ${buildLegacyPreviewSummaryHtml(intake.legacy_preview)}
                    <div class="dashboard-legacy-preview-card__actions">
                        <button type="button"
                                class="btn btn-sm btn-primary"
                                data-dashboard-search-intake-action>
                            Create Service Request
                        </button>
                    </div>
                </div>
            `
            : `
                <button type="button"
                        class="btn btn-sm btn-primary"
                        data-dashboard-search-intake-action>
                    Create Service Request
                </button>
            `;

        panel.innerHTML = `${previewHtml}<div data-dashboard-search-order-history class="mt-2"></div>`;

        renderIntakeOrderHistory(
            panel.querySelector('[data-dashboard-search-order-history]'),
            intake.order_history,
        );

        const actionButton = panel.querySelector('[data-dashboard-search-intake-action]');

        actionButton?.addEventListener('click', () => {
            if (isLegacyOneClickEligible(intake)) {
                openLegacySearchConfirmModal(intake, query);

                return;
            }

            prefillAndOpenQuickCreate(intake, query);
        });

        banner.appendChild(panel);
    };

    initLegacySearchConfirmModal({
        showToast,
        onLegacyCreateSuccess: async (data, { query }) => {
            const actions = {
                incident_id: data.incident_id,
                display_reference: data.display_reference,
            };

            if (query.trim() !== '') {
                suppressNextAutoOpen = true;
                await runUniversalSearch(query);
                suppressNextAutoOpen = false;
            }

            openCustomer360FromSearch(actions, dashboardIntegration);
            refreshCustomer360(actions.incident_id);
        },
    });

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

    const applySearchRows = async (incidentIds, matchCount, query, results = [], orderHistory = null) => {
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

        const rowsAuth = await handleHttpAuthStatus(rowsResponse);

        if (rowsAuth.action === 'redirect' || rowsAuth.action === 'reload') {
            return false;
        }

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

        if (matchCount === 1 && incidentIds.length === 1 && !suppressNextAutoOpen) {
            const incidentId = incidentIds[0];
            highlightSearchMatch(card, incidentId);

            const row = document.getElementById(`service-case-row-${incidentId}`);
            const referenceLabel = row?.querySelector('.case-reference-link')?.textContent?.trim() ?? '';
            const historicalInvoice = typeof results[0]?.actions?.historical_invoice === 'string'
                && results[0].actions.historical_invoice !== ''
                ? results[0].actions.historical_invoice
                : null;

            if (historicalInvoice) {
                await dashboardIntegration.openDrawer?.(incidentId, referenceLabel, { historicalInvoice });
            } else {
                await dashboardIntegration.openDrawer?.(incidentId, referenceLabel);
            }
        } else {
            clearSearchMatchHighlight(card);
        }

        hideSearchResultActions(card);

        return true;
    };

    const restoreDashboard = async () => {
        setDashboardSearchActive(false);
        hideIntakeFallback(getDashboardCard());
        hideHistoricalFallback(getDashboardCard());
        hideSearchResultActions(getDashboardCard());
        hideSearchOrderHistoryPanel(getDashboardCard());
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
        lastSearchQuery = trimmedQuery;

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

            const searchAuth = await handleHttpAuthStatus(response);

            if (searchAuth.action === 'redirect' || searchAuth.action === 'reload') {
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
            const historical = data.historical ?? null;

            if (incidentIds.length === 0) {
                showSearchEmptyResults(card);

                if (historical || intake) {
                    showSearchBanner(card, { matchCount: 0, query: trimmedQuery, intake, historical });
                    if (historical) {
                        showHistoricalFallback(card, historical);
                    }
                    if (intake) {
                        showIntakeFallback(card, intake, trimmedQuery);
                    }
                } else {
                    showSearchBanner(card, { matchCount, query: trimmedQuery });
                }

                dashboardIntegration.onRowsUpdated?.();

                return;
            }

            hideIntakeFallback(card);
            hideHistoricalFallback(card);
            hideSearchResultActions(card);
            hideSearchOrderHistoryPanel(card);
            showSearchBanner(card, { matchCount, query: trimmedQuery });

            await applySearchRows(
                incidentIds,
                matchCount,
                trimmedQuery,
                data.results ?? [],
                data.order_history ?? null,
            );
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

    getDashboardCard()?.addEventListener('click', (event) => {
        if (event.target.closest('[data-dashboard-search-clear]')) {
            event.preventDefault();

            if (globalInput) {
                globalInput.value = '';
            }

            clearSearch();

            return;
        }

        if (event.target.closest('[data-dashboard-empty-search-again]')) {
            event.preventDefault();
            globalInput?.focus();
            globalInput?.select();
        }
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

    const parseDebounceMs = (raw, fallback) => {
        const parsed = Number.parseInt(raw ?? '', 10);

        return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
    };

    const searchDebounceMs = parseDebounceMs(form?.dataset.universalSearchDebounceMs, 350);
    const searchMinLength = parseDebounceMs(form?.dataset.universalSearchMinLength, 3);
    let liveSearchTimer = null;

    globalInput?.addEventListener('input', () => {
        const trimmed = (globalInput.value ?? '').trim();

        window.clearTimeout(liveSearchTimer);

        if (trimmed === '') {
            clearSearch();

            return;
        }

        if (!dashboardIntegration || trimmed.length < searchMinLength) {
            return;
        }

        liveSearchTimer = window.setTimeout(() => {
            runUniversalSearch(trimmed);
        }, searchDebounceMs);
    });

    const pendingQuery = new URLSearchParams(window.location.search).get('q')?.trim() ?? '';

    if (pendingQuery !== '' && globalInput) {
        globalInput.value = pendingQuery;

        if (dashboardIntegration) {
            runUniversalSearch(pendingQuery);
        }
    }
};
