import './bootstrap';
import * as bootstrap from 'bootstrap';
import { initLiveDashboard } from './live-dashboard';
import { initLiveNotifications } from './live-notifications';
import { initServiceCaseShow } from './service-case-show';

window.bootstrap = bootstrap;

const SIDEBAR_STORAGE_KEY = 'radium.sidebarExpanded';

const isSidebarExpanded = () => localStorage.getItem(SIDEBAR_STORAGE_KEY) === 'true';

const applySidebarState = (expanded) => {
    document.documentElement.classList.toggle('sidebar-expanded', expanded);
};

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const initTooltips = (root = document) => {
    root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        const existing = bootstrap.Tooltip.getInstance(element);

        if (existing) {
            existing.dispose();
        }

        bootstrap.Tooltip.getOrCreateInstance(element);
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
    toastElement.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">
                <i class="bi bi-check-circle me-1"></i> ${message}
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    `;

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

const replaceServiceCaseRow = (incidentId, rowHtml) => {
    const existingRow = document.getElementById(`service-case-row-${incidentId}`);

    if (!existingRow || !rowHtml) {
        return;
    }

    existingRow.outerHTML = rowHtml;
    initTooltips(document.getElementById(`service-case-row-${incidentId}`));
};

const initDashboardTransactions = () => {
    const card = document.querySelector('.dashboard-service-cases-card');

    if (!card) {
        return;
    }

    const bulkBar = card.querySelector('[data-bulk-bar]');
    const bulkCount = card.querySelector('[data-bulk-count]');
    const bulkInput = card.querySelector('[data-bulk-transaction-input]');
    const bulkApply = card.querySelector('[data-bulk-apply]');
    const bulkUrl = card.dataset.bulkUrl;
    const tbody = card.querySelector('#dashboard-service-cases-body');
    const selectAll = card.querySelector('[data-select-all]');

    const selectedCheckboxes = () => Array.from(card.querySelectorAll('.service-case-select:checked'));

    const updateBulkBar = () => {
        if (!bulkBar) {
            return;
        }

        const selected = selectedCheckboxes();
        const count = selected.length;

        bulkBar.classList.toggle('d-none', count === 0);

        if (bulkCount) {
            bulkCount.textContent = String(count);
        }

        if (bulkApply && bulkInput) {
            bulkApply.disabled = count === 0 || bulkInput.value.trim() === '';
        }
    };

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
                error.textContent = 'Transaction ID is required.';
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
                const message = data.errors?.transaction_id?.[0] ?? data.message ?? 'Unable to save transaction ID.';
                input.classList.add('is-invalid');

                if (error) {
                    error.textContent = message;
                }

                return;
            }

            if (data.row_html && data.incident_id) {
                replaceServiceCaseRow(data.incident_id, data.row_html);
                updateBulkBar();
            }

            showAppToast(data.message ?? 'Transaction ID saved.');
        } catch (saveError) {
            input.classList.add('is-invalid');

            if (error) {
                error.textContent = 'Unable to save transaction ID.';
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
                const checked = event.target.checked;
                card.querySelectorAll('.service-case-select').forEach((checkbox) => {
                    checkbox.checked = checked;
                });
            }

            updateBulkBar();
        }
    });

    bulkInput?.addEventListener('input', updateBulkBar);

    bulkApply?.addEventListener('click', async () => {
        if (!bulkUrl || !bulkInput) {
            return;
        }

        const incidentIds = selectedCheckboxes().map((checkbox) => Number(checkbox.value));
        const transactionId = bulkInput.value.trim();

        if (incidentIds.length === 0 || transactionId === '') {
            return;
        }

        bulkApply.disabled = true;

        try {
            const response = await fetch(bulkUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    incident_ids: incidentIds,
                    transaction_id: transactionId,
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                const message = data.errors?.transaction_id?.[0]
                    ?? data.errors?.incident_ids?.[0]
                    ?? data.message
                    ?? 'Unable to apply transaction ID.';
                showAppToast(message, 'danger');
                return;
            }

            data.rows?.forEach(({ incident_id: incidentId, html }) => {
                replaceServiceCaseRow(incidentId, html);
            });

            bulkInput.value = '';

            if (selectAll) {
                selectAll.checked = false;
            }

            updateBulkBar();
            showAppToast(data.message ?? 'Transaction applied.');
        } catch (bulkError) {
            showAppToast('Unable to apply transaction ID.', 'danger');
        } finally {
            bulkApply.disabled = false;
            updateBulkBar();
        }
    });
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
    initDashboardTransactions();
    initLiveDashboard();
    initLiveNotifications();
    initServiceCaseShow();

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
            if (quickCreateModalElement.dataset.resetOnShow === 'true') {
                resetQuickCreateForm();
            }
        });

        if (quickCreateModalElement.dataset.showOnLoad === 'true') {
            quickCreateModal.show();
        }

        quickCreateModalElement.addEventListener('hidden.bs.modal', () => {
            quickCreateModalElement.querySelectorAll('.is-invalid').forEach((field) => {
                field.classList.remove('is-invalid');
            });
        });
    }
});
