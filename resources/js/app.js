import './bootstrap';
import * as bootstrap from 'bootstrap';
import { initLiveDashboard, applyKpis, applyRows, refreshDashboard } from './live-dashboard';
import { initLiveDashboardReverb } from './live-dashboard-reverb';
import { initDashboardQuickFilter } from './dashboard-filter';
import { initDashboardSerialNumbers } from './dashboard-serial';
import { initDashboardLoadMore } from './dashboard-load-more';
import { initDashboardKpiActions } from './dashboard-kpi';
import { initServiceCasePaginationState } from './dashboard-service-case-state';
import { initBatchTransactionForm } from './dashboard-batch-transaction';
import { initLiveNotifications } from './live-notifications';
import { createServiceCaseRowReplacer } from './service-case-row';
import { initServiceCaseShow } from './service-case-show';
import { initOrderWorkspace } from './order-workspace';
import { initTooltips } from './tooltips';
import { createBatchTransactionSession } from './workspace/batch-session';
import { csrfToken } from './workspace/http';
import { initActionDialog } from './workspace/action-dialog';
import { initWorkspace, getWorkspaceSession } from './workspace';
import { initKeyboardShortcuts } from './keyboard';
import { initUniversalSearch } from './universal-search';
import { initCustomer360Drawer } from './customer-360-drawer';
import { initOperationsDashboard } from './operations-dashboard';

window.bootstrap = bootstrap;

const SIDEBAR_STORAGE_KEY = 'radium.sidebarExpanded';

const isSidebarExpanded = () => localStorage.getItem(SIDEBAR_STORAGE_KEY) === 'true';

const applySidebarState = (expanded) => {
    document.documentElement.classList.toggle('sidebar-expanded', expanded);
};

const initMentionTextareas = (root = document) => {
    root.querySelectorAll('[data-mention-textarea]').forEach((textarea) => {
        if (textarea.dataset.mentionBound === 'true') {
            return;
        }

        textarea.dataset.mentionBound = 'true';

        const listId = textarea.dataset.mentionList;
        const datalist = listId ? document.getElementById(listId) : null;

        if (!datalist) {
            return;
        }

        const users = Array.from(datalist.options).map((option) => option.value);

        const dropdown = document.createElement('div');
        dropdown.className = 'mention-suggestions dropdown-menu';
        dropdown.setAttribute('role', 'listbox');
        document.body.appendChild(dropdown);

        let activeIndex = -1;
        let mentionStart = -1;

        const hideDropdown = () => {
            dropdown.classList.remove('show');
            dropdown.style.display = 'none';
            activeIndex = -1;
            mentionStart = -1;
        };

        const getMentionMatch = () => {
            const cursorPos = textarea.selectionStart ?? textarea.value.length;
            const before = textarea.value.slice(0, cursorPos);
            const match = before.match(/@([\p{L}\p{M}'.]*)$/u);

            if (!match) {
                return null;
            }

            return {
                term: match[1],
                start: before.length - match[0].length,
            };
        };

        const filterUsers = (term) => {
            const lower = term.toLowerCase();

            if (lower === '') {
                return users;
            }

            return users.filter((name) => name.toLowerCase().startsWith(lower));
        };

        const positionDropdown = () => {
            const rect = textarea.getBoundingClientRect();
            dropdown.style.top = `${rect.bottom}px`;
            dropdown.style.left = `${rect.left}px`;
            dropdown.style.minWidth = `${rect.width}px`;
        };

        const setActiveItem = (index) => {
            const items = dropdown.querySelectorAll('.dropdown-item');

            items.forEach((item, itemIndex) => {
                item.classList.toggle('active', itemIndex === index);
            });
            activeIndex = index;
        };

        const applyMention = (name) => {
            if (mentionStart < 0) {
                return;
            }

            const cursorPos = textarea.selectionStart ?? textarea.value.length;
            const before = textarea.value.slice(0, mentionStart);
            const after = textarea.value.slice(cursorPos);
            textarea.value = `${before}@${name} ${after}`;
            const newPos = before.length + name.length + 2;
            textarea.setSelectionRange(newPos, newPos);
            hideDropdown();
            textarea.focus();
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        };

        const showDropdown = (matches) => {
            if (matches.length === 0) {
                hideDropdown();

                return;
            }

            dropdown.innerHTML = matches.map((name) => (
                `<button type="button" class="dropdown-item" role="option" data-mention-name="${name}">${name}</button>`
            )).join('');

            positionDropdown();
            dropdown.classList.add('show');
            dropdown.style.display = 'block';
            setActiveItem(0);
        };

        const refreshDropdown = () => {
            const mentionMatch = getMentionMatch();

            if (!mentionMatch) {
                hideDropdown();

                return;
            }

            mentionStart = mentionMatch.start;
            showDropdown(filterUsers(mentionMatch.term));
        };

        textarea.addEventListener('input', refreshDropdown);
        textarea.addEventListener('click', refreshDropdown);
        textarea.addEventListener('keyup', refreshDropdown);

        textarea.addEventListener('keydown', (event) => {
            if (!dropdown.classList.contains('show')) {
                return;
            }

            const items = dropdown.querySelectorAll('.dropdown-item');

            if (items.length === 0) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setActiveItem((activeIndex + 1) % items.length);

                return;
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                setActiveItem((activeIndex - 1 + items.length) % items.length);

                return;
            }

            if (event.key === 'Enter' || event.key === 'Tab') {
                if (activeIndex >= 0 && items[activeIndex]) {
                    event.preventDefault();
                    applyMention(items[activeIndex].dataset.mentionName);
                }

                return;
            }

            if (event.key === 'Escape') {
                hideDropdown();
            }
        });

        dropdown.addEventListener('mousedown', (event) => {
            const item = event.target.closest('[data-mention-name]');

            if (!item) {
                return;
            }

            event.preventDefault();
            applyMention(item.dataset.mentionName);
        });

        textarea.addEventListener('blur', () => {
            window.setTimeout(() => {
                if (!dropdown.contains(document.activeElement)) {
                    hideDropdown();
                }
            }, 150);
        });
    });
};

const showAppToast = (message, variant = 'success') => {
    let container = document.querySelector('.toast-container');

    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        document.body.appendChild(container);
    }

    const toastElement = document.createElement('div');
    toastElement.className = `toast align-items-center text-bg-${variant} border-0`;
    toastElement.setAttribute('role', 'alert');
    toastElement.setAttribute('aria-live', 'assertive');
    toastElement.setAttribute('aria-atomic', 'true');

    const body = document.createElement('div');
    body.className = 'toast-body';
    body.style.whiteSpace = 'pre-line';
    body.textContent = message ?? '';

    const wrapper = document.createElement('div');
    wrapper.className = 'd-flex';
    wrapper.appendChild(body);

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'btn-close btn-close-white me-2 m-auto';
    closeButton.setAttribute('data-bs-dismiss', 'toast');
    closeButton.setAttribute('aria-label', 'Close');
    wrapper.appendChild(closeButton);

    toastElement.appendChild(wrapper);

    container.appendChild(toastElement);

    const toast = bootstrap.Toast.getOrCreateInstance(toastElement, {
        autohide: true,
        delay: 4500,
    });

    toastElement.addEventListener('hidden.bs.toast', () => {
        toastElement.remove();
    });

    toast.show();
};

const initDashboardTransactions = ({ pageRoot, openBatchModal, onRowUpdated } = {}) => {
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
        const input = cell.querySelector('.transaction-inline-input');
        const error = cell.querySelector('.transaction-inline-error');
        const storeUrl = cell.dataset.storeUrl;
        const incidentId = cell.dataset.incidentId;
        const transactionId = input?.value.trim() ?? '';

        if (!storeUrl || !input || transactionId === '') {
            input?.classList.add('is-invalid');

            if (error) {
                error.textContent = 'Service reference is required.';
            }

            return;
        }

        input.disabled = true;

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

                return;
            }

            if (data.row_html && data.incident_id) {
                getWorkspaceSession().release('inline-transaction');
                replaceServiceCaseRow(data.incident_id, data.row_html);
                batchSession.updateToolbar();
            }

            if (data.kpi_strip_html !== undefined) {
                applyKpis(data.kpi_strip_html);
            }

            showAppToast(data.message ?? 'Service reference saved.');
        } catch (saveError) {
            input.classList.add('is-invalid');

            if (error) {
                error.textContent = 'Unable to save service reference.';
            }
        } finally {
            input.disabled = false;
        }
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
        closeOpenInlineEditor,
    };
};

document.addEventListener('DOMContentLoaded', () => {
    applySidebarState(isSidebarExpanded());

    const toggleButtons = document.querySelectorAll('[data-sidebar-toggle]');

    toggleButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const expanded = !document.documentElement.classList.contains('sidebar-expanded');
            applySidebarState(expanded);
            localStorage.setItem(SIDEBAR_STORAGE_KEY, expanded ? 'true' : 'false');
        });
    });

    document.querySelectorAll('[data-toast-show]').forEach((element) => {
        bootstrap.Toast.getOrCreateInstance(element, {
            autohide: true,
            delay: 4500,
        }).show();
    });

    initTooltips();

    const pageRoot = document.getElementById('dashboard-page') ?? document;
    initServiceCasePaginationState(pageRoot);
    initDashboardKpiActions(pageRoot);
    const replaceServiceCaseRowFallback = createServiceCaseRowReplacer({ initTooltips });
    const dashboardTransactionsRef = { current: null };
    const dashboardSerialRef = { current: null };
    let dashboardQuickFilter = null;

    const workspaceApi = initWorkspace({
        showToast: showAppToast,
        replaceServiceCaseRow: (...args) => (
            dashboardTransactionsRef.current?.replaceServiceCaseRow ?? replaceServiceCaseRowFallback
        )(...args),
        initTooltips,
        initMentionTextareas,
        afterSuccess: async (data) => {
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
        },
        afterOpen: (_incidentId, component, _context, opened) => {
            if (!opened) {
                return;
            }

            const modalHost = document.querySelector('[data-workspace-modal-host]');
            const modalContent = document.querySelector('[data-workspace-modal-content]');

            modalHost?.classList.toggle('workspace-modal--compact', component === 'action' || component === 'remark');

            if (component === 'remark' || component === 'action' || component === 'resolve' || component === 'close') {
                initMentionTextareas(modalContent);
            }

            if (component === 'action') {
                initActionDialog(modalContent);
            }

            if (component === 'batch-transaction') {
                initBatchTransactionForm(modalContent, showAppToast);
                initTooltips(modalContent);
            }
        },
        afterClose: (host) => {
            host?.classList.remove('workspace-modal--compact');
        },
    });

    dashboardTransactionsRef.current = initDashboardTransactions({
        pageRoot,
        openBatchModal: (incidentIds) => {
            workspaceApi?.openBatchComponent('batch-transaction', incidentIds, 'dashboard');
        },
        onRowUpdated: () => {
            dashboardQuickFilter?.reapply();
        },
    });

    const dashboardTransactions = dashboardTransactionsRef.current;

    dashboardQuickFilter = initDashboardQuickFilter({
        pageRoot,
        onFilterApplied: () => {
            dashboardTransactions?.batchSession.updateToolbar();
        },
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
        showToast: showAppToast,
        initTooltips,
    });

    const dashboardLiveHooks = {
        onRowsUpdated: () => {
            dashboardTransactions?.batchSession.restoreAllRowStates();
            dashboardQuickFilter?.reapply();
        },
    };

    initUniversalSearch({
        dashboardIntegration: pageRoot?.querySelector('.dashboard-service-cases-card') ? {
            pageRoot,
            searchRowsUrl: pageRoot.dataset.dashboardSearchRowsUrl,
            applyRows: (rows, options = {}) => {
                applyRows(rows, options);
            },
            restoreDashboard: () => refreshDashboard(pageRoot),
            openDrawer: (incidentId, referenceLabel) => customer360Drawer?.open(incidentId, referenceLabel),
            closeDrawer: () => customer360Drawer?.close(),
            onRowsUpdated: dashboardLiveHooks.onRowsUpdated,
        } : null,
    });

    const liveDashboard = initLiveDashboard(dashboardLiveHooks);
    const liveMode = liveDashboard.pageRoot?.dataset.liveMode ?? 'poll';

    if (liveMode === 'reverb' || liveMode === 'auto') {
        initLiveDashboardReverb({
            pageRoot: liveDashboard.pageRoot,
            startPolling: liveDashboard.startPolling,
            stopPolling: liveDashboard.stopPolling,
            hooks: dashboardLiveHooks,
            fallbackPoll: liveMode === 'auto',
        });
    }

    initLiveNotifications();
    initServiceCaseShow();
    initMentionTextareas(document.querySelector('[data-service-case-show]') ?? document);
    initOrderWorkspace();

    const quickCreateModalElement = document.getElementById('quickCreateModal');

    if (quickCreateModalElement) {
        const quickCreateModal = bootstrap.Modal.getOrCreateInstance(quickCreateModalElement);
        const quickCreateForm = quickCreateModalElement.querySelector('form');

        const resetQuickCreateForm = () => {
            if (!quickCreateForm) {
                return;
            }

            quickCreateForm.reset();

            const productField = quickCreateForm.querySelector('#quick_product');
            if (productField) {
                productField.value = 'MFS 110';
            }

            const sourceField = quickCreateForm.querySelector('#quick_source');
            if (sourceField) {
                sourceField.value = 'call';
            }

            quickCreateForm.querySelectorAll('.is-invalid').forEach((field) => {
                field.classList.remove('is-invalid');
            });
        };

        quickCreateModalElement.addEventListener('show.bs.modal', () => {
            getWorkspaceSession().acquire('quick-create');

            if (quickCreateModalElement.dataset.resetOnShow === 'true') {
                resetQuickCreateForm();
            }
        });

        const shouldReopenQuickCreate = quickCreateModalElement.dataset.showOnLoad === 'true'
            || pageRoot?.dataset.reopenQuickCreate === 'true';

        if (shouldReopenQuickCreate) {
            window.setTimeout(() => {
                quickCreateModal.show();
            }, 0);
        }

        quickCreateModalElement.addEventListener('hidden.bs.modal', () => {
            getWorkspaceSession().release('quick-create');

            quickCreateModalElement.querySelectorAll('.is-invalid').forEach((field) => {
                field.classList.remove('is-invalid');
            });
        });
    }

    dashboardSerialRef.current = initDashboardSerialNumbers({
        replaceServiceCaseRow: (...args) => (
            dashboardTransactionsRef.current?.replaceServiceCaseRow ?? replaceServiceCaseRowFallback
        )(...args),
        showToast: showAppToast,
    });

    initKeyboardShortcuts({
        closeOpenInlineEditor: () => (
            dashboardTransactionsRef.current?.closeOpenInlineEditor?.()
            || dashboardSerialRef.current?.closeOpenInlineEditor?.()
            || false
        ),
        isWorkspaceSubmitBusy: () => workspaceApi?.isBusy?.('submit') ?? false,
        openDashboardQuickFilter: () => dashboardQuickFilter?.open?.(),
    });

    initOperationsDashboard();
});
