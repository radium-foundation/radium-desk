import * as bootstrap from 'bootstrap';
import { csrfToken } from './workspace/http';
import { getWorkspaceSession } from './workspace';

export const initDashboardSerialNumbers = ({
    replaceServiceCaseRow,
    showToast,
} = {}) => {
    const card = document.querySelector('.dashboard-service-cases-card');
    const entryModalElement = document.getElementById('serialNumberModal');
    const confirmModalElement = document.getElementById('serialNumberConfirmModal');

    if (!card || !entryModalElement || !confirmModalElement) {
        return null;
    }

    const entryModal = bootstrap.Modal.getOrCreateInstance(entryModalElement);
    const confirmModal = bootstrap.Modal.getOrCreateInstance(confirmModalElement);
    const input = entryModalElement.querySelector('#dashboard_serial_number');
    const error = entryModalElement.querySelector('#dashboard_serial_number_error');
    const saveButton = entryModalElement.querySelector('[data-serial-modal-save]');
    const backButton = confirmModalElement.querySelector('[data-serial-confirm-back]');
    const confirmButton = confirmModalElement.querySelector('[data-serial-confirm-lock]');
    const confirmValue = confirmModalElement.querySelector('#serial_number_confirm_value');

    let activeContext = null;
    let suppressEntryReset = false;
    let confirmCloseReason = null;
    let pendingSerialNumber = '';
    let pendingEntryError = null;

    const resetForm = () => {
        if (input) {
            input.value = '';
            input.classList.remove('is-invalid');
        }

        if (error) {
            error.textContent = '';
        }

        if (confirmValue) {
            confirmValue.textContent = '';
        }

        activeContext = null;
        pendingSerialNumber = '';
        pendingEntryError = null;
        suppressEntryReset = false;
        confirmCloseReason = null;
    };

    const openEntryModal = (trigger) => {
        activeContext = {
            storeUrl: trigger.dataset.storeUrl,
            incidentId: trigger.dataset.incidentId,
            orderId: trigger.dataset.orderId,
        };

        if (input) {
            input.value = '';
            input.classList.remove('is-invalid');
        }

        if (error) {
            error.textContent = '';
        }

        pendingEntryError = null;
        entryModal.show();
    };

    const showValidationError = (message) => {
        input?.classList.add('is-invalid');

        if (error) {
            error.textContent = message;
        }
    };

    const proceedToConfirmation = () => {
        if (!input) {
            return;
        }

        const serialNumber = input.value.trim().toUpperCase();

        if (serialNumber === '') {
            showValidationError('Serial number is required.');

            return;
        }

        pendingSerialNumber = serialNumber;
        pendingEntryError = null;

        if (confirmValue) {
            confirmValue.textContent = serialNumber;
        }

        suppressEntryReset = true;
        entryModal.hide();
    };

    const returnToEntryModal = () => {
        confirmCloseReason = 'back';
        confirmModal.hide();
    };

    const resumeEntryModal = () => {
        suppressEntryReset = true;
        entryModal.show();
    };

    const saveSerialNumber = async () => {
        if (!activeContext?.storeUrl || pendingSerialNumber === '') {
            return;
        }

        confirmButton?.setAttribute('disabled', 'disabled');
        backButton?.setAttribute('disabled', 'disabled');

        try {
            const response = await fetch(activeContext.storeUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    serial_number: pendingSerialNumber,
                    incident_id: Number(activeContext.incidentId),
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                pendingEntryError = data.errors?.serial_number?.[0]
                    ?? data.message
                    ?? 'Unable to save serial number.';
                confirmCloseReason = 'validation_error';
                confirmModal.hide();

                return;
            }

            if (data.row_html && data.incident_id && replaceServiceCaseRow) {
                replaceServiceCaseRow(data.incident_id, data.row_html);
            }

            confirmCloseReason = 'success';
            confirmModal.hide();
            showToast?.(data.message ?? 'Serial number saved.');
        } catch (saveError) {
            pendingEntryError = 'Unable to save serial number.';
            confirmCloseReason = 'validation_error';
            confirmModal.hide();
        } finally {
            confirmButton?.removeAttribute('disabled');
            backButton?.removeAttribute('disabled');
        }
    };

    card.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-serial-modal-trigger="true"]');

        if (trigger) {
            event.preventDefault();
            openEntryModal(trigger);
        }
    });

    saveButton?.addEventListener('click', () => {
        proceedToConfirmation();
    });

    backButton?.addEventListener('click', () => {
        returnToEntryModal();
    });

    confirmButton?.addEventListener('click', () => {
        saveSerialNumber();
    });

    input?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            proceedToConfirmation();
        }
    });

    confirmModalElement.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
        }
    });

    input?.addEventListener('input', () => {
        input.classList.remove('is-invalid');

        if (error) {
            error.textContent = '';
        }
    });

    entryModalElement.addEventListener('show.bs.modal', () => {
        getWorkspaceSession().acquire('serial-modal', {
            incidentId: activeContext?.incidentId ? Number(activeContext.incidentId) : undefined,
        });
    });

    entryModalElement.addEventListener('shown.bs.modal', () => {
        if (pendingEntryError) {
            if (input && pendingSerialNumber !== '') {
                input.value = pendingSerialNumber;
            }

            showValidationError(pendingEntryError);
            pendingEntryError = null;
        }

        input?.focus();
    });

    entryModalElement.addEventListener('hidden.bs.modal', () => {
        if (suppressEntryReset) {
            suppressEntryReset = false;
            confirmModal.show();

            return;
        }

        getWorkspaceSession().release('serial-modal');
        resetForm();
    });

    confirmModalElement.addEventListener('shown.bs.modal', () => {
        confirmButton?.focus();
    });

    confirmModalElement.addEventListener('hidden.bs.modal', () => {
        const reason = confirmCloseReason;
        confirmCloseReason = null;

        if (reason === 'success') {
            getWorkspaceSession().release('serial-modal');
            resetForm();

            return;
        }

        if (reason === 'back' || reason === 'validation_error') {
            resumeEntryModal();

            return;
        }

        if (activeContext) {
            resumeEntryModal();
        }
    });

    return {
        openEntryModal,
    };
};
