import * as bootstrap from 'bootstrap';
import { applyKpis } from './live-dashboard';
import { csrfToken } from './workspace/http';
import { getWorkspaceSession } from './workspace';

export const initDashboardDeviceModels = ({
    replaceServiceCaseRow,
    showToast,
    getBatchSession,
} = {}) => {
    const card = document.querySelector('.dashboard-service-cases-card');
    const modalElement = document.getElementById('deviceModelAssignModal');

    if (!card || !modalElement) {
        return null;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const titleElement = modalElement.querySelector('[data-device-model-modal-title]');
    const selectElement = modalElement.querySelector('[data-device-model-select]')
        ?? modalElement.querySelector('#device_model_select');
    const errorElement = modalElement.querySelector('[data-device-model-error]');
    const saveButton = modalElement.querySelector('[data-device-model-save]');
    const batchUrl = modalElement.dataset.batchUrl ?? '';

    const TITLES = {
        single: 'Assign Model',
        bulk: 'Assign Model to Selected Requests',
    };

    let mode = 'single';
    let activeCell = null;
    let bulkIncidentIds = [];

    const clearValidation = () => {
        selectElement?.classList.remove('is-invalid');

        if (errorElement) {
            errorElement.textContent = '';
        }
    };

    const resetModalState = () => {
        mode = 'single';
        activeCell = null;
        bulkIncidentIds = [];

        if (selectElement) {
            selectElement.value = '';
        }

        clearValidation();
    };

    const showValidationError = (message) => {
        selectElement?.classList.add('is-invalid');

        if (errorElement) {
            errorElement.textContent = message;
        }
    };

    const getSelectedDeviceModelId = () => {
        const value = selectElement?.value ?? '';

        return value === '' ? null : Number(value);
    };

    const openModal = ({ assignMode, cell = null, incidentIds = [] }) => {
        mode = assignMode;
        activeCell = cell;
        bulkIncidentIds = incidentIds;

        if (titleElement) {
            titleElement.textContent = TITLES[assignMode] ?? TITLES.single;
        }

        if (selectElement) {
            selectElement.value = '';
        }

        clearValidation();

        getWorkspaceSession().acquire('device-model-modal', {
            mode: assignMode,
            incidentId: cell ? Number(cell.dataset.incidentId) : incidentIds[0] ?? null,
        });

        modal.show();
    };

    const openSingleModal = (cell) => {
        openModal({ assignMode: 'single', cell });
    };

    const openBulkModal = (incidentIds) => {
        if (incidentIds.length === 0) {
            return;
        }

        openModal({ assignMode: 'bulk', incidentIds });
    };

    const handleBatchResult = (data) => {
        const batchSession = getBatchSession?.();

        if (!batchSession) {
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

    const applyBulkRefresh = (data) => {
        if (data.refresh?.replace_rows && replaceServiceCaseRow) {
            data.refresh.replace_rows.forEach((row) => {
                replaceServiceCaseRow(row.incident_id, row.html);
            });
        }

        if (data.refresh?.kpis_html?.kpi_strip_html !== undefined) {
            applyKpis(data.refresh.kpis_html.kpi_strip_html);
        }
    };

    const saveSingleDeviceModel = async (deviceModelId) => {
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
            showValidationError(
                data.errors?.device_model_id?.[0]
                    ?? data.message
                    ?? 'Please select a model.',
            );

            return false;
        }

        if (data.row_html && data.incident_id && replaceServiceCaseRow) {
            replaceServiceCaseRow(data.incident_id, data.row_html);
        }

        showToast?.(data.message ?? 'Model assigned.');

        return true;
    };

    const saveBulkDeviceModel = async (deviceModelId) => {
        const response = await fetch(batchUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                device_model_id: deviceModelId,
                incident_ids: bulkIncidentIds,
                workspace_context: 'dashboard',
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            showValidationError(
                data.errors?.device_model_id?.[0]
                    ?? data.message
                    ?? 'Please select a model.',
            );

            return false;
        }

        applyBulkRefresh(data);
        handleBatchResult(data);
        showToast?.(data.toast?.message ?? data.message ?? 'Model assigned.');

        return data.success ?? false;
    };

    const saveDeviceModel = async () => {
        const deviceModelId = getSelectedDeviceModelId();

        if (deviceModelId === null) {
            showValidationError('Please select a model.');

            return;
        }

        if (mode === 'single' && !activeCell?.dataset.storeUrl) {
            return;
        }

        if (mode === 'bulk' && (bulkIncidentIds.length === 0 || batchUrl === '')) {
            return;
        }

        saveButton?.setAttribute('disabled', 'disabled');

        try {
            const succeeded = mode === 'bulk'
                ? await saveBulkDeviceModel(deviceModelId)
                : await saveSingleDeviceModel(deviceModelId);

            if (! succeeded) {
                return;
            }

            getWorkspaceSession().release('device-model-modal');
            modal.hide();
        } catch (saveError) {
            showValidationError('Unable to assign model.');
        } finally {
            saveButton?.removeAttribute('disabled');
        }
    };

    card.addEventListener('click', (event) => {
        const cell = event.target.closest('[data-device-model-cell="true"]');

        if (cell && event.target.closest('.device-model-cell-trigger')) {
            openSingleModal(cell);
        }
    });

    selectElement?.addEventListener('change', () => {
        clearValidation();
    });

    saveButton?.addEventListener('click', () => {
        saveDeviceModel();
    });

    modalElement.addEventListener('hidden.bs.modal', () => {
        getWorkspaceSession().release('device-model-modal');
        resetModalState();
    });

    return {
        openBulkModal,
    };
};
