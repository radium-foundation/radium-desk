import * as bootstrap from 'bootstrap';
import { csrfToken } from './workspace/http';
import { getWorkspaceSession } from './workspace';

export const initDashboardSerialNumbers = ({
    replaceServiceCaseRow,
    showToast,
} = {}) => {
    const card = document.querySelector('.dashboard-service-cases-card');
    const confirmModalElement = document.getElementById('serialNumberConfirmModal');

    if (!card || !confirmModalElement) {
        return null;
    }

    const confirmModal = bootstrap.Modal.getOrCreateInstance(confirmModalElement);
    const backButton = confirmModalElement.querySelector('[data-serial-confirm-back]');
    const confirmButton = confirmModalElement.querySelector('[data-serial-confirm-lock]');
    const confirmValue = confirmModalElement.querySelector('#serial_number_confirm_value');

    let activeCell = null;
    let confirmCloseReason = null;
    let pendingSerialNumber = '';
    let pendingInlineError = null;

    const getInlineElements = (cell) => ({
        trigger: cell.querySelector('.serial-cell-trigger'),
        editor: cell.querySelector('.transaction-inline-editor'),
        input: cell.querySelector('.serial-inline-input'),
        error: cell.querySelector('.serial-inline-error'),
    });

    const resetConfirmState = () => {
        activeCell = null;
        pendingSerialNumber = '';
        pendingInlineError = null;
        confirmCloseReason = null;

        if (confirmValue) {
            confirmValue.textContent = '';
        }
    };

    const showInlineValidationError = (cell, message) => {
        const { input, error } = getInlineElements(cell);

        input?.classList.add('is-invalid');

        if (error) {
            error.textContent = message;
        }
    };

    const openInlineEditor = (cell) => {
        const { trigger, editor, input, error } = getInlineElements(cell);

        if (!editor || !input) {
            return;
        }

        trigger?.classList.add('d-none');
        editor.classList.remove('d-none');
        cell.classList.add('is-serial-inline-open');

        if (error) {
            error.textContent = '';
        }

        input.classList.remove('is-invalid');
        input.value = pendingSerialNumber;

        if (pendingInlineError) {
            showInlineValidationError(cell, pendingInlineError);
            pendingInlineError = null;
        }

        input.focus();

        getWorkspaceSession().acquire('inline-serial', {
            incidentId: Number(cell.dataset.incidentId),
        });
    };

    const closeInlineEditor = (cell, { focusTrigger = true, keepSession = false } = {}) => {
        const { trigger, editor, input, error } = getInlineElements(cell);

        editor?.classList.add('d-none');
        trigger?.classList.remove('d-none');
        cell.classList.remove('is-serial-inline-open');
        input?.classList.remove('is-invalid');

        if (error) {
            error.textContent = '';
        }

        if (! keepSession) {
            getWorkspaceSession().release('inline-serial');
        }

        if (focusTrigger) {
            trigger?.focus();
        }
    };

    const proceedToConfirmation = (cell) => {
        const { input } = getInlineElements(cell);
        const serialNumber = input?.value.trim().toUpperCase() ?? '';

        if (!input || serialNumber === '') {
            showInlineValidationError(cell, 'Serial number is required.');

            return;
        }

        activeCell = cell;
        pendingSerialNumber = serialNumber;

        if (confirmValue) {
            confirmValue.textContent = serialNumber;
        }

        closeInlineEditor(cell, { focusTrigger: false, keepSession: true });
        confirmModal.show();
    };

    const returnToInlineEditor = () => {
        confirmCloseReason = 'back';
        confirmModal.hide();
    };

    const saveSerialNumber = async () => {
        if (!activeCell?.dataset.storeUrl || pendingSerialNumber === '') {
            return;
        }

        confirmButton?.setAttribute('disabled', 'disabled');
        backButton?.setAttribute('disabled', 'disabled');

        try {
            const response = await fetch(activeCell.dataset.storeUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    serial_number: pendingSerialNumber,
                    incident_id: Number(activeCell.dataset.incidentId),
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                pendingInlineError = data.errors?.serial_number?.[0]
                    ?? data.message
                    ?? 'Unable to save serial number.';
                confirmCloseReason = 'validation_error';
                confirmModal.hide();

                return;
            }

            if (data.row_html && data.incident_id && replaceServiceCaseRow) {
                getWorkspaceSession().release('inline-serial');
                replaceServiceCaseRow(data.incident_id, data.row_html);
            }

            confirmCloseReason = 'success';
            confirmModal.hide();
            showToast?.(data.message);
        } catch (saveError) {
            pendingInlineError = 'Unable to save serial number.';
            confirmCloseReason = 'validation_error';
            confirmModal.hide();
        } finally {
            confirmButton?.removeAttribute('disabled');
            backButton?.removeAttribute('disabled');
        }
    };

    card.addEventListener('click', (event) => {
        const cell = event.target.closest('[data-inline-serial="true"]');

        if (cell && event.target.closest('.serial-cell-trigger')) {
            pendingSerialNumber = '';
            pendingInlineError = null;
            openInlineEditor(cell);

            return;
        }

        const saveButton = event.target.closest('.serial-inline-save');

        if (saveButton) {
            const editorCell = saveButton.closest('[data-inline-serial="true"]');

            if (editorCell) {
                proceedToConfirmation(editorCell);
            }
        }
    });

    card.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') {
            return;
        }

        const input = event.target.closest('.serial-inline-input');

        if (input) {
            event.preventDefault();
            const editorCell = input.closest('[data-inline-serial="true"]');

            if (editorCell) {
                proceedToConfirmation(editorCell);
            }
        }
    });

    card.addEventListener('input', (event) => {
        const input = event.target.closest('.serial-inline-input');

        if (!input) {
            return;
        }

        input.classList.remove('is-invalid');
        const cell = input.closest('[data-inline-serial="true"]');
        const { error } = getInlineElements(cell);

        if (error) {
            error.textContent = '';
        }
    });

    backButton?.addEventListener('click', () => {
        returnToInlineEditor();
    });

    confirmButton?.addEventListener('click', () => {
        saveSerialNumber();
    });

    confirmModalElement.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
        }
    });

    confirmModalElement.addEventListener('shown.bs.modal', () => {
        confirmButton?.focus();
    });

    confirmModalElement.addEventListener('hidden.bs.modal', () => {
        const reason = confirmCloseReason;
        const cell = activeCell;
        confirmCloseReason = null;

        if (reason === 'success') {
            getWorkspaceSession().release('inline-serial');
            resetConfirmState();

            return;
        }

        if (cell) {
            openInlineEditor(cell);
        }
    });

    const closeOpenInlineEditor = () => {
        if (confirmModalElement.classList.contains('show')) {
            return false;
        }

        const openEditor = card.querySelector('[data-inline-serial="true"] .transaction-inline-editor:not(.d-none)');

        if (!openEditor) {
            return false;
        }

        const cell = openEditor.closest('[data-inline-serial="true"]');

        if (!cell) {
            return false;
        }

        resetConfirmState();
        closeInlineEditor(cell);

        return true;
    };

    return {
        closeOpenInlineEditor,
    };
};
