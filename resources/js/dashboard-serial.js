import * as bootstrap from 'bootstrap';
import { csrfToken } from './workspace/http';
import { getWorkspaceSession } from './workspace';

export const initDashboardSerialNumbers = ({
    replaceServiceCaseRow,
    showToast,
} = {}) => {
    const card = document.querySelector('.dashboard-service-cases-card');
    const modalElement = document.getElementById('serialNumberModal');

    if (!card || !modalElement) {
        return null;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const input = modalElement.querySelector('#dashboard_serial_number');
    const error = modalElement.querySelector('#dashboard_serial_number_error');
    const saveButton = modalElement.querySelector('[data-serial-modal-save]');

    let activeContext = null;

    const resetForm = () => {
        if (input) {
            input.value = '';
            input.classList.remove('is-invalid');
        }

        if (error) {
            error.textContent = '';
        }

        activeContext = null;
    };

    const openModal = (trigger) => {
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

        modal.show();
    };

    const saveSerialNumber = async () => {
        if (!activeContext?.storeUrl || !input) {
            return;
        }

        const serialNumber = input.value.trim().toUpperCase();

        if (serialNumber === '') {
            input.classList.add('is-invalid');

            if (error) {
                error.textContent = 'Serial number is required.';
            }

            return;
        }

        input.disabled = true;
        saveButton?.setAttribute('disabled', 'disabled');

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
                    serial_number: serialNumber,
                    incident_id: Number(activeContext.incidentId),
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                const message = data.errors?.serial_number?.[0] ?? data.message ?? 'Unable to save serial number.';
                input.classList.add('is-invalid');

                if (error) {
                    error.textContent = message;
                }

                return;
            }

            if (data.row_html && data.incident_id && replaceServiceCaseRow) {
                replaceServiceCaseRow(data.incident_id, data.row_html);
            }

            modal.hide();
            showToast?.(data.message ?? 'Serial number saved.');
        } catch (saveError) {
            input.classList.add('is-invalid');

            if (error) {
                error.textContent = 'Unable to save serial number.';
            }
        } finally {
            input.disabled = false;
            saveButton?.removeAttribute('disabled');
        }
    };

    card.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-serial-modal-trigger="true"]');

        if (trigger) {
            event.preventDefault();
            openModal(trigger);
        }
    });

    saveButton?.addEventListener('click', () => {
        saveSerialNumber();
    });

    input?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            saveSerialNumber();
        }
    });

    input?.addEventListener('input', () => {
        input.classList.remove('is-invalid');

        if (error) {
            error.textContent = '';
        }
    });

    modalElement.addEventListener('show.bs.modal', () => {
        getWorkspaceSession().acquire('serial-modal', {
            incidentId: activeContext?.incidentId ? Number(activeContext.incidentId) : undefined,
        });
    });

    modalElement.addEventListener('shown.bs.modal', () => {
        input?.focus();
    });

    modalElement.addEventListener('hidden.bs.modal', () => {
        getWorkspaceSession().release('serial-modal');
        resetForm();
    });

    return {
        openModal,
    };
};
