import * as bootstrap from 'bootstrap';
import { csrfToken } from './workspace/http';
import { getWorkspaceSession } from './workspace';

const filterDeviceModelOptions = (container, term) => {
    const normalizedTerm = term.trim().toLowerCase();

    container.querySelectorAll('[data-device-model-name]').forEach((option) => {
        const name = option.dataset.deviceModelName ?? '';
        option.classList.toggle('d-none', normalizedTerm !== '' && ! name.includes(normalizedTerm));
    });
};

export const initDashboardDeviceModels = ({
    replaceServiceCaseRow,
    showToast,
} = {}) => {
    const card = document.querySelector('.dashboard-service-cases-card');
    const modalElement = document.getElementById('deviceModelAssignModal');

    if (!card) {
        return null;
    }

    const modal = modalElement ? bootstrap.Modal.getOrCreateInstance(modalElement) : null;
    const searchInput = modalElement?.querySelector('[data-device-model-search]');
    const optionsContainer = modalElement?.querySelector('[data-device-model-options]');
    const errorElement = modalElement?.querySelector('[data-device-model-error]');
    const saveButton = modalElement?.querySelector('[data-device-model-save]');

    let activeCell = null;

    const resetModalState = () => {
        activeCell = null;

        if (searchInput) {
            searchInput.value = '';
        }

        if (optionsContainer) {
            filterDeviceModelOptions(optionsContainer, '');
            optionsContainer.querySelectorAll('[data-device-model-radio]').forEach((radio) => {
                radio.checked = false;
            });
        }

        if (errorElement) {
            errorElement.textContent = '';
        }
    };

    const getSelectedDeviceModelId = () => {
        const selected = optionsContainer?.querySelector('[data-device-model-radio]:checked');

        return selected ? Number(selected.value) : null;
    };

    const openAssignModal = (cell) => {
        if (!modal) {
            return;
        }

        activeCell = cell;
        resetModalState();
        getWorkspaceSession().acquire('device-model-modal', {
            incidentId: Number(cell.dataset.incidentId),
        });
        modal.show();
    };

    const saveDeviceModel = async () => {
        const deviceModelId = getSelectedDeviceModelId();

        if (!activeCell?.dataset.storeUrl || deviceModelId === null) {
            if (errorElement) {
                errorElement.textContent = 'Please select a device model.';
            }

            return;
        }

        saveButton?.setAttribute('disabled', 'disabled');

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
                    device_model_id: deviceModelId,
                    incident_id: Number(activeCell.dataset.incidentId),
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                if (errorElement) {
                    errorElement.textContent = data.errors?.device_model_id?.[0]
                        ?? data.message
                        ?? 'Unable to assign device model.';
                }

                return;
            }

            if (data.row_html && data.incident_id && replaceServiceCaseRow) {
                replaceServiceCaseRow(data.incident_id, data.row_html);
            }

            getWorkspaceSession().release('device-model-modal');
            modal?.hide();
            showToast?.(data.message ?? 'Device model assigned.');
        } catch (saveError) {
            if (errorElement) {
                errorElement.textContent = 'Unable to assign device model.';
            }
        } finally {
            saveButton?.removeAttribute('disabled');
        }
    };

    card.addEventListener('click', (event) => {
        const cell = event.target.closest('[data-device-model-cell="true"]');

        if (cell && event.target.closest('.device-model-cell-trigger')) {
            openAssignModal(cell);
        }
    });

    searchInput?.addEventListener('input', () => {
        if (optionsContainer) {
            filterDeviceModelOptions(optionsContainer, searchInput.value);
        }
    });

    saveButton?.addEventListener('click', () => {
        saveDeviceModel();
    });

    modalElement?.addEventListener('hidden.bs.modal', () => {
        getWorkspaceSession().release('device-model-modal');
        resetModalState();
    });

    const bindBatchSearch = (root = document) => {
        const batchSearch = root.querySelector('[data-batch-device-model-search]');
        const batchOptions = root.querySelector('[data-batch-device-model-options]');

        if (!batchSearch || !batchOptions || batchSearch.dataset.bound === 'true') {
            return;
        }

        batchSearch.dataset.bound = 'true';
        batchSearch.addEventListener('input', () => {
            filterDeviceModelOptions(batchOptions, batchSearch.value);
        });
    };

    return {
        bindBatchSearch,
    };
};
