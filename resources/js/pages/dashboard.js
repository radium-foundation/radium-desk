import { applyKpis, applyRows, refreshDashboard } from '../live-dashboard';
import { initLiveDashboard } from '../live-dashboard';
import { configureDashboardPolling } from '../live-dashboard-polling';
import { initLiveDashboardReverb } from '../live-dashboard-reverb';
import { reconcileReadyQueueMembership } from '../ready-queue-membership-reconcile';
import { initDashboardQuickFilter } from '../dashboard-filter';
import { initDashboardSerialNumbers } from '../dashboard-serial';
import { initDashboardLoadMore } from '../dashboard-load-more';
import { initDashboardKpiActions } from '../dashboard-kpi';
import { initViewOnlyMetricRefresh } from '../dashboard-live-counts';
import { initOperationsWorkspaceSoftSwitch } from '../dashboard-operations-workspace';
import { initServiceCasePaginationState } from '../dashboard-service-case-state';
import { createServiceCaseRowReplacer } from '../service-case-row';
import { initTooltips } from '../tooltips';
import { createBatchTransactionSession } from '../workspace/batch-session';
import { csrfToken } from '../workspace/http';
import { getWorkspaceSession } from '../workspace/session';
import { initCustomer360Drawer } from '../customer-360-drawer';
import { applyLiveRefreshNextAppointment, initAgentDashboard } from '../agent-dashboard';
import { initDashboardActivityStreams } from '../dashboard-activity-streams';
import { initDashboardActivityRefresh } from '../dashboard-activity-refresh';
import { initDashboardTeamActivity } from '../dashboard-team-activity';
import { buildSmartToastActions } from '../customer-360-cockpit';
import { getDashboardConfig } from '../dashboard-config';
import { initUniversalSearch } from '../universal-search';
import { initCustomerIntake, initLegacyVerificationModal, guardServiceReferenceAssignment } from '../customer-intake';
import { setOrderWorkspaceLegacyVerificationModal } from '../order-workspace';
import { createCustomer360AwareToast, showAppToast } from '../core/toast';
import { initMentionTextareas } from '../core/mention-textareas';
import { keyboardShortcutHooks, workspaceApiRef, workspaceHooks } from '../core/workspace-registry';

const initDashboardTransactions = ({ pageRoot, openBatchModal, onRowUpdated, legacyVerificationModal } = {}) => {
    const card = document.querySelector('.dashboard-service-cases-card');

    if (!card) {
        return null;
    }

    let batchSession;

    const replaceServiceCaseRow = createServiceCaseRowReplacer({
        initTooltips,
        onRowReplaced: (incidentId) => {
            batchSession?.restoreRowState(incidentId);
            batchSession?.updateToolbar();
            onRowUpdated?.();
        },
    });

    const removeServiceCaseRow = (incidentId) => {
        document.getElementById(`service-case-row-${incidentId}`)?.remove();
        batchSession?.restoreRowState(incidentId);
        batchSession?.updateToolbar();
        onRowUpdated?.();
    };

    batchSession = createBatchTransactionSession({
        card,
        pageRoot: pageRoot ?? document,
        openBatchModal,
    });

    const openInlineEditor = (cell) => {
        const trigger = cell.querySelector('.transaction-cell-trigger');
        const editor = cell.querySelector('.transaction-inline-editor');
        const input = cell.querySelector('.transaction-inline-input');
        const error = cell.querySelector('.transaction-inline-error');

        if (!editor || !input) {
            return;
        }

        trigger?.classList.add('d-none');
        editor.classList.remove('d-none');

        if (error) {
            error.textContent = '';
        }

        input.classList.remove('is-invalid');
        input.value = '';
        input.focus();

        getWorkspaceSession().acquire('inline-transaction', {
            incidentId: Number(cell.dataset.incidentId),
        });
    };

    const closeInlineEditor = (cell) => {
        const trigger = cell.querySelector('.transaction-cell-trigger');
        const editor = cell.querySelector('.transaction-inline-editor');
        const input = cell.querySelector('.transaction-inline-input');
        const error = cell.querySelector('.transaction-inline-error');

        editor?.classList.add('d-none');
        trigger?.classList.remove('d-none');
        input?.classList.remove('is-invalid');

        if (error) {
            error.textContent = '';
        }

        getWorkspaceSession().release('inline-transaction');
    };

    const saveInlineTransaction = async (cell) => {
        if (cell.dataset.saving === '1') {
            return;
        }

        const input = cell.querySelector('.transaction-inline-input');
        const error = cell.querySelector('.transaction-inline-error');
        const saveButton = cell.querySelector('.transaction-inline-save');
        const storeUrl = cell.dataset.storeUrl;
        const incidentId = cell.dataset.incidentId;
        const transactionId = input?.value.trim() ?? '';
        const requiresLegacyVerification = cell.dataset.requiresLegacyVerification === 'true';
        const legacyVerificationUrl = cell.dataset.legacyVerificationUrl;
        const legacyVerificationMode = cell.dataset.legacyVerificationMode ?? 'customer';

        if (!storeUrl || !input || transactionId === '') {
            input?.classList.add('is-invalid');

            if (error) {
                error.textContent = 'Service reference is required.';
            }

            return;
        }

        const performSave = async () => {
            // Acquire the in-flight lock synchronously before any await so
            // double-clicks / repeated Enter cannot start a second POST.
            if (cell.dataset.saving === '1') {
                return;
            }

            cell.dataset.saving = '1';

            const previousButtonHtml = saveButton?.innerHTML ?? '';
            input.disabled = true;

            if (saveButton) {
                saveButton.disabled = true;
                saveButton.setAttribute('aria-busy', 'true');
                saveButton.innerHTML = 'Saving…';
            }

            const releaseSavingState = () => {
                delete cell.dataset.saving;
                input.disabled = false;

                if (saveButton) {
                    saveButton.disabled = false;
                    saveButton.removeAttribute('aria-busy');
                    saveButton.innerHTML = previousButtonHtml;
                }
            };

            try {
                const response = await fetch(storeUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        transaction_id: transactionId,
                        incident_id: Number(incidentId),
                    }),
                });

                const data = await response.json();

                if (!response.ok) {
                    const message = data.errors?.transaction_id?.[0] ?? data.message ?? 'Unable to save service reference.';
                    input.classList.add('is-invalid');

                    if (error) {
                        error.textContent = message;
                    }

                    releaseSavingState();

                    return;
                }

                const removeIncidentIds = [
                    ...(data.remove_row?.incident_id !== undefined ? [Number(data.remove_row.incident_id)] : []),
                    ...((data.remove_rows ?? []).map((row) => Number(row.incident_id))),
                ].filter((id) => Number.isFinite(id) && id > 0);

                getWorkspaceSession().release('inline-transaction');

                if (removeIncidentIds.length > 0) {
                    Array.from(new Set(removeIncidentIds)).forEach((id) => {
                        removeServiceCaseRow(id);
                    });
                } else if (data.row_html && data.incident_id) {
                    replaceServiceCaseRow(data.incident_id, data.row_html);
                    batchSession.updateToolbar();
                }

                if (data.kpi_strip_html !== undefined) {
                    applyKpis(data.kpi_strip_html);
                }

                showAppToast(data.message ?? 'Service reference saved.');

                // Success replaces/removes the row; only release if the editor cell remains.
                if (cell.isConnected) {
                    releaseSavingState();
                }
            } catch (saveError) {
                input.classList.add('is-invalid');

                if (error) {
                    error.textContent = 'Unable to save service reference.';
                }

                releaseSavingState();
            }
        };

        if (requiresLegacyVerification && legacyVerificationUrl && legacyVerificationModal) {
            guardServiceReferenceAssignment({
                requiresLegacyVerification: true,
                legacyVerificationUrl,
                legacyVerificationModal,
                legacyVerificationMode,
                onProceed: performSave,
            });

            return;
        }

        await performSave();
    };

    card.addEventListener('click', (event) => {
        const cell = event.target.closest('[data-inline-transaction="true"]');

        if (cell && event.target.closest('.transaction-cell-trigger')) {
            openInlineEditor(cell);

            return;
        }

        const saveButton = event.target.closest('.transaction-inline-save');

        if (saveButton) {
            const editorCell = saveButton.closest('[data-inline-transaction="true"]');

            if (editorCell) {
                saveInlineTransaction(editorCell);
            }
        }
    });

    card.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') {
            return;
        }

        const input = event.target.closest('.transaction-inline-input');

        if (input) {
            event.preventDefault();
            const editorCell = input.closest('[data-inline-transaction="true"]');

            if (editorCell) {
                saveInlineTransaction(editorCell);
            }
        }
    });

    card.addEventListener('change', (event) => {
        if (event.target.matches('.service-case-select, [data-select-all]')) {
            if (event.target.matches('[data-select-all]')) {
                batchSession.handleSelectAll(event.target.checked);

                return;
            }

            batchSession.handleCheckboxChange(event.target);
        }
    });

    const closeOpenInlineEditor = () => {
        const openEditor = card.querySelector('.transaction-inline-editor:not(.d-none)');

        if (!openEditor) {
            return false;
        }

        const cell = openEditor.closest('[data-inline-transaction="true"]');

        if (!cell) {
            return false;
        }

        const trigger = cell.querySelector('.transaction-cell-trigger');

        closeInlineEditor(cell);
        trigger?.focus();

        return true;
    };

    return {
        batchSession,
        replaceServiceCaseRow,
        removeServiceCaseRow,
        closeOpenInlineEditor,
    };
};

workspaceHooks.openBatchModal = (incidentIds) => {
    workspaceApiRef?.openBatchComponent('batch-transaction', incidentIds, 'dashboard');
};

export const bootDashboard = () => {
    const dashboardConfig = getDashboardConfig();

    if (!dashboardConfig) {
        return;
    }

    const { pageRoot } = dashboardConfig;
    const dashboardTransactionsRef = { current: null };

    configureDashboardPolling({
        refreshDashboard,
        reconcileReadyQueueMembership,
        getWorkspaceSession,
    });

    const dashboardSerialRef = { current: null };
    const customer360DrawerRef = { current: null };
    let dashboardQuickFilter = null;
    const showCustomer360AwareToast = createCustomer360AwareToast(customer360DrawerRef, buildSmartToastActions);

    workspaceHooks.showToast = showCustomer360AwareToast;

    const legacyVerificationModal = initLegacyVerificationModal();
    setOrderWorkspaceLegacyVerificationModal(legacyVerificationModal);

    initServiceCasePaginationState(pageRoot);
    initDashboardKpiActions(pageRoot);
    initViewOnlyMetricRefresh(pageRoot);

    dashboardTransactionsRef.current = initDashboardTransactions({
        pageRoot,
        legacyVerificationModal,
        openBatchModal: (incidentIds) => {
            workspaceHooks.openBatchModal?.(incidentIds);
        },
        onRowUpdated: () => {
            dashboardQuickFilter?.reapply();
        },
    });

    const dashboardTransactions = dashboardTransactionsRef.current;

    workspaceHooks.replaceServiceCaseRow = (...args) => dashboardTransactionsRef.current?.replaceServiceCaseRow(...args);
    workspaceHooks.removeServiceCaseRow = (incidentId) => dashboardTransactionsRef.current?.removeServiceCaseRow?.(incidentId);
    workspaceHooks.afterSuccess = async (data) => {
        const batchSession = dashboardTransactionsRef.current?.batchSession;

        if (!batchSession) {
            return;
        }

        if (data.action !== 'batch-transaction') {
            batchSession.restoreAllRowStates();

            return;
        }

        const failedIncidents = data.extensions?.failed_incidents ?? [];
        const succeededIncidentIds = data.extensions?.succeeded_incident_ids ?? [];

        if (failedIncidents.length === 0 && data.success) {
            batchSession.clearSelection();
        } else {
            batchSession.handleBatchResult(succeededIncidentIds, failedIncidents);
        }

        batchSession.restoreAllRowStates();
    };

    dashboardQuickFilter = initDashboardQuickFilter({
        pageRoot,
        loadMoreUrl: dashboardConfig.dashboardLoadMoreUrl,
        onRestoreDashboard: () => refreshDashboard(pageRoot),
        onFilterApplied: () => {
            dashboardTransactions?.batchSession.updateToolbar();
        },
    });

    initOperationsWorkspaceSoftSwitch({
        pageRoot,
        clearQuickFilter: () => dashboardQuickFilter?.clearFilter?.(),
    });

    initDashboardLoadMore({
        pageRoot,
        onRowsAppended: () => {
            dashboardTransactions?.batchSession.restoreAllRowStates();
            dashboardQuickFilter?.reapply();
        },
    });

    const customer360Drawer = initCustomer360Drawer({
        pageRoot,
        showToast: showCustomer360AwareToast,
        initTooltips,
    });
    customer360DrawerRef.current = customer360Drawer;

    const agentDashboardRef = { current: initAgentDashboard({
        pageRoot,
        showToast: showAppToast,
    }) };

    initDashboardTeamActivity(pageRoot);

    // Legacy My Activity path (feature-flag rollback only).
    if (pageRoot.querySelector('[data-dashboard-activity-feed]')) {
        initDashboardActivityStreams(pageRoot);
        initDashboardActivityRefresh(pageRoot);
    }

    document.addEventListener('dashboard:live-refresh', (event) => {
        applyLiveRefreshNextAppointment(agentDashboardRef.current, event.detail);
    });

    const dashboardLiveHooks = {
        onRowsUpdated: () => {
            dashboardTransactions?.batchSession.restoreAllRowStates();
            dashboardQuickFilter?.reapply();
        },
    };

    initUniversalSearch({
        showToast: showAppToast,
        dashboardIntegration: pageRoot.querySelector('.dashboard-service-cases-card') ? {
            pageRoot,
            searchRowsUrl: dashboardConfig.dashboardSearchRowsUrl,
            applyRows: (rows, options = {}) => {
                applyRows(rows, options);
            },
            restoreDashboard: () => refreshDashboard(pageRoot),
            openDrawer: (incidentId, referenceLabel, options) => (
                options === undefined
                    ? customer360Drawer?.open(incidentId, referenceLabel)
                    : customer360Drawer?.open(incidentId, referenceLabel, options)
            ),
            closeDrawer: () => customer360Drawer?.close(),
            onRowsUpdated: dashboardLiveHooks.onRowsUpdated,
        } : null,
    });

    const liveDashboard = initLiveDashboard(dashboardLiveHooks);
    const liveMode = pageRoot?.dataset.liveMode ?? 'poll';
    const liveUpdatesEnabled = pageRoot?.dataset.liveUpdatesEnabled !== '0';
    const hasEcho = Boolean(pageRoot?.dataset.echoKey);
    const shouldInitEcho = hasEcho && (
        liveMode === 'reverb'
        || liveMode === 'auto'
        || ! liveUpdatesEnabled
    );

    let realtimeHandle = null;

    if (shouldInitEcho) {
        realtimeHandle = initLiveDashboardReverb({
            pageRoot,
            hooks: dashboardLiveHooks,
            dashboardLiveUpdates: liveUpdatesEnabled,
        });
    }

    void liveDashboard;
    void realtimeHandle;

    dashboardSerialRef.current = initDashboardSerialNumbers({
        replaceServiceCaseRow: (...args) => (
            dashboardTransactionsRef.current?.replaceServiceCaseRow(...args)
        ),
        showToast: showAppToast,
    });

    initCustomerIntake({
        showToast: showAppToast,
        dashboardIntegration: pageRoot.querySelector('.dashboard-service-cases-card') ? {
            openDrawer: (incidentId, referenceLabel, options) => (
                options === undefined
                    ? customer360Drawer?.open(incidentId, referenceLabel)
                    : customer360Drawer?.open(incidentId, referenceLabel, options)
            ),
        } : null,
    });

    keyboardShortcutHooks.closeOpenInlineEditor = () => (
        dashboardTransactionsRef.current?.closeOpenInlineEditor?.()
        || dashboardSerialRef.current?.closeOpenInlineEditor?.()
        || false
    );
    keyboardShortcutHooks.openDashboardQuickFilter = () => dashboardQuickFilter?.open?.();
};

document.addEventListener('DOMContentLoaded', bootDashboard);
