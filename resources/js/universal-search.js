import * as bootstrap from 'bootstrap';
import { advanceQuickCreateToNewContact } from './customer-intake';
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
import { csrfToken } from './workspace/http';

const SEARCH_ICON_HTML = '<i class="bi bi-search"></i>';
const SEARCH_LOADING_HTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
const SEARCH_MATCH_CLASS = 'dashboard-case-row--search-match';
const SEARCH_FETCH_ERROR = 'Unable to load search results. Please try again.';
const SEARCH_ROWS_ERROR = 'Unable to load matching service cases. Please try again.';
const INTAKE_FALLBACK_SELECTOR = '[data-dashboard-search-intake-fallback]';
const SEARCH_RESULT_ACTIONS_SELECTOR = '[data-dashboard-search-result-actions]';

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

    if (dashboardIntegration?.openDrawer) {
        dashboardIntegration.openDrawer(incidentId, referenceLabel);

        return;
    }

    document.dispatchEvent(new CustomEvent('customer360:open', {
        detail: {
            incidentId,
            referenceLabel,
        },
    }));
};

const reopenServiceCaseFromSearch = async (
    actions,
    button,
    errorElement,
    dashboardIntegration,
    { onSuccess } = {},
) => {
    if (!actions?.reopen_url) {
        return;
    }

    if (errorElement) {
        errorElement.classList.add('d-none');
        errorElement.textContent = '';
    }

    if (button) {
        button.disabled = true;
    }

    try {
        const response = await fetch(actions.reopen_url, {
            method: 'PATCH',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                workspace_context: actions.reopen_workspace_context,
                action_type: 'reopen',
                body: 'Reopened from global search.',
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            if (errorElement) {
                errorElement.textContent = data.message ?? 'Unable to reopen service case.';
                errorElement.classList.remove('d-none');
            }

            return;
        }

        await onSuccess?.(actions);
    } catch {
        if (errorElement) {
            errorElement.textContent = 'Unable to reopen service case.';
            errorElement.classList.remove('d-none');
        }
    } finally {
        if (button) {
            button.disabled = false;
        }
    }
};

const buildSearchResultActionItemHtml = (result) => {
    const actions = result.actions ?? {};
    const reopenButton = actions.can_reopen
        ? '<button type="button" class="btn btn-outline-secondary" data-search-reopen-case>Reopen</button>'
        : '';

    return `
        <div class="dashboard-search-result-action list-group-item px-0 py-2 border-0 border-bottom"
             data-search-result-action>
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="small min-w-0">
                    <span class="fw-semibold">${result.service_case ?? actions.display_reference ?? '—'}</span>
                    <span class="text-muted">
                        · ${result.order_id ?? '—'}
                        · ${result.status ?? actions.status_label ?? '—'}
                    </span>
                </div>
                <div class="btn-group btn-group-sm flex-shrink-0" role="group" aria-label="Service case actions">
                    <button type="button"
                            class="btn btn-outline-primary"
                            data-search-open-customer-360>
                        Customer 360
                    </button>
                    ${reopenButton}
                </div>
            </div>
            <div class="alert alert-danger py-1 px-2 small mb-0 mt-2 d-none"
                 data-search-result-action-error
                 role="alert"></div>
        </div>
    `;
};

const wireSearchResultActionItem = (item, actions, dashboardIntegration, { onReopenSuccess } = {}) => {
    const errorElement = item.querySelector('[data-search-result-action-error]');

    item.querySelector('[data-search-open-customer-360]')?.addEventListener('click', () => {
        openCustomer360FromSearch(actions, dashboardIntegration);
    });

    item.querySelector('[data-search-reopen-case]')?.addEventListener('click', (event) => {
        reopenServiceCaseFromSearch(
            actions,
            event.currentTarget,
            errorElement,
            dashboardIntegration,
            { onSuccess: onReopenSuccess },
        );
    });
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

    const handleReopenSuccess = async (actions) => {
        if (lastSearchQuery) {
            suppressNextAutoOpen = true;
            await runUniversalSearch(lastSearchQuery);
            suppressNextAutoOpen = false;
        }

        openCustomer360FromSearch(actions, dashboardIntegration);
        refreshCustomer360(actions.incident_id);
    };

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

    const hideSearchResultActions = (card) => {
        card?.querySelector(SEARCH_RESULT_ACTIONS_SELECTOR)?.remove();
    };

    const showSearchResultActions = (card, results) => {
        hideSearchResultActions(card);

        const banner = card?.querySelector('[data-dashboard-search-banner]');
        const serviceCaseResults = (results ?? []).filter((result) => result?.type === 'service_case' && result?.actions);

        if (!banner || serviceCaseResults.length === 0) {
            return;
        }

        const panel = document.createElement('div');
        panel.className = 'dashboard-search-result-actions border-top px-3 py-2';
        panel.dataset.dashboardSearchResultActions = '';

        panel.innerHTML = `
            <div class="list-group list-group-flush">
                ${serviceCaseResults.map((result) => buildSearchResultActionItemHtml(result)).join('')}
            </div>
        `;

        panel.querySelectorAll('[data-search-result-action]').forEach((item, index) => {
            wireSearchResultActionItem(
                item,
                serviceCaseResults[index].actions,
                dashboardIntegration,
                { onReopenSuccess: handleReopenSuccess },
            );
        });

        banner.appendChild(panel);
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

        panel.innerHTML = previewHtml;

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

    const applySearchRows = async (incidentIds, matchCount, query, results = []) => {
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

        if (matchCount === 1 && incidentIds.length === 1 && !suppressNextAutoOpen) {
            const incidentId = incidentIds[0];
            highlightSearchMatch(card, incidentId);

            const row = document.getElementById(`service-case-row-${incidentId}`);
            const referenceLabel = row?.querySelector('.case-reference-link')?.textContent?.trim() ?? '';

            await dashboardIntegration.openDrawer?.(incidentId, referenceLabel);
        } else {
            clearSearchMatchHighlight(card);
        }

        showSearchResultActions(card, results);

        return true;
    };

    const restoreDashboard = async () => {
        setDashboardSearchActive(false);
        hideIntakeFallback(getDashboardCard());
        hideSearchResultActions(getDashboardCard());
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
                    showSearchBanner(card, { matchCount: 0, query: trimmedQuery, intake });
                    showIntakeFallback(card, intake, trimmedQuery);
                } else {
                    showSearchBanner(card, { matchCount, query: trimmedQuery });
                }

                dashboardIntegration.onRowsUpdated?.();

                return;
            }

            hideIntakeFallback(card);
            hideSearchResultActions(card);
            showSearchBanner(card, { matchCount, query: trimmedQuery });

            await applySearchRows(incidentIds, matchCount, trimmedQuery, data.results ?? []);
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
