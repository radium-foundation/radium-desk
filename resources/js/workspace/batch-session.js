import { getWorkspaceSession } from './session';

export const createBatchTransactionSession = ({
    card,
    pageRoot = document,
    openBatchModal,
    openBatchDeviceModelModal,
}) => {
    const session = getWorkspaceSession();

    const selectedIncidentIds = new Set();

    const getBulkBar = () => pageRoot.querySelector('[data-bulk-bar]');
    const getBulkCount = () => pageRoot.querySelector('[data-bulk-count]');
    const getIdleHint = () => pageRoot.querySelector('[data-bulk-idle-hint]');
    const getSelectedLabel = () => pageRoot.querySelector('[data-bulk-selected-label]');
    const getAssignButton = () => pageRoot.querySelector('[data-batch-assign]');
    const getDeviceModelAssignButton = () => pageRoot.querySelector('[data-batch-device-model-assign]');
    const getClearButton = () => pageRoot.querySelector('[data-batch-clear]');
    const getSelectAll = () => card.querySelector('[data-select-all]');

    const getSelectedIncidentIds = () => Array.from(selectedIncidentIds);

    const getRow = (incidentId) => document.getElementById(`service-case-row-${incidentId}`);

    const isRowFilteredOut = (row) => row?.classList.contains('dashboard-case-row--filtered-out') ?? false;

    const getVisibleCheckboxes = () => Array.from(card.querySelectorAll('.service-case-select'))
        .filter((checkbox) => ! isRowFilteredOut(checkbox.closest('tr')));

    const syncRowVisualState = (incidentId) => {
        const row = getRow(incidentId);
        const isSelected = selectedIncidentIds.has(Number(incidentId));

        row?.classList.toggle('dashboard-case-row--selected', isSelected);
    };

    const syncAllRowVisualStates = () => {
        card.querySelectorAll('.service-case-select').forEach((checkbox) => {
            syncRowVisualState(Number(checkbox.value));
        });
    };

    const syncDomSelectionFromState = () => {
        card.querySelectorAll('.service-case-select').forEach((checkbox) => {
            checkbox.checked = selectedIncidentIds.has(Number(checkbox.value));
        });

        syncAllRowVisualStates();
    };

    const syncSession = () => {
        const selected = getSelectedIncidentIds();

        if (selected.length > 0) {
            session.acquire('bulk-selection', { incidentIds: selected });
        } else {
            session.release('bulk-selection');
        }
    };

    const updateToolbar = () => {
        syncDomSelectionFromState();
        const count = selectedIncidentIds.size;
        const bulkBar = getBulkBar();
        const bulkCount = getBulkCount();
        const idleHint = getIdleHint();
        const selectedLabel = getSelectedLabel();
        const assignButton = getAssignButton();
        const deviceModelAssignButton = getDeviceModelAssignButton();
        const clearButton = getClearButton();
        const selectAll = getSelectAll();
        const isActive = count > 0;

        bulkBar?.classList.toggle('dashboard-bulk-toolbar--active', isActive);
        idleHint?.classList.toggle('d-none', isActive);
        selectedLabel?.classList.toggle('d-none', ! isActive);

        if (bulkCount) {
            bulkCount.textContent = String(count);
        }

        if (assignButton) {
            assignButton.disabled = ! isActive;
        }

        if (deviceModelAssignButton) {
            deviceModelAssignButton.disabled = ! isActive;
        }

        if (clearButton) {
            clearButton.disabled = ! isActive;
            clearButton.classList.toggle('d-none', ! isActive);
        }

        if (selectAll) {
            const visibleCheckboxes = getVisibleCheckboxes();
            const selectedVisibleCount = visibleCheckboxes
                .filter((checkbox) => selectedIncidentIds.has(Number(checkbox.value)))
                .length;

            selectAll.checked = visibleCheckboxes.length > 0
                && selectedVisibleCount === visibleCheckboxes.length;
            selectAll.indeterminate = selectedVisibleCount > 0
                && selectedVisibleCount < visibleCheckboxes.length;
        }

        syncSession();
    };

    const restoreRowState = (incidentId) => {
        const normalizedIncidentId = Number(incidentId);
        const checkbox = card.querySelector(`.service-case-select[value="${normalizedIncidentId}"]`);

        if (checkbox) {
            checkbox.checked = selectedIncidentIds.has(normalizedIncidentId);
            syncRowVisualState(normalizedIncidentId);
        }
    };

    const restoreAllRowStates = () => {
        card.querySelectorAll('.service-case-select').forEach((checkbox) => {
            restoreRowState(Number(checkbox.value));
        });

        updateToolbar();
    };

    const clearSelection = () => {
        selectedIncidentIds.clear();

        card.querySelectorAll('.service-case-select').forEach((checkbox) => {
            checkbox.checked = false;
            syncRowVisualState(Number(checkbox.value));
        });

        const selectAll = getSelectAll();

        if (selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        }

        updateToolbar();
    };

    const handleCheckboxChange = (checkbox) => {
        const incidentId = Number(checkbox.value);

        if (checkbox.checked) {
            selectedIncidentIds.add(incidentId);
        } else {
            selectedIncidentIds.delete(incidentId);
        }

        updateToolbar();
    };

    const handleSelectAll = (checked) => {
        if (checked) {
            getVisibleCheckboxes().forEach((checkbox) => {
                selectedIncidentIds.add(Number(checkbox.value));
            });
        } else {
            selectedIncidentIds.clear();
        }

        updateToolbar();
    };

    const handleBatchResult = (succeededIncidentIds = [], failedIncidents = []) => {
        succeededIncidentIds.forEach((incidentId) => {
            selectedIncidentIds.delete(Number(incidentId));
        });

        failedIncidents.forEach(({ incident_id: incidentId }) => {
            selectedIncidentIds.add(Number(incidentId));
        });

        if (selectedIncidentIds.size === 0) {
            clearSelection();

            return;
        }

        updateToolbar();
    };

    const openAssignModal = () => {
        const incidentIds = getSelectedIncidentIds();

        if (incidentIds.length === 0) {
            return;
        }

        if (typeof openBatchModal !== 'function') {
            console.error('Batch transaction modal is unavailable.');

            return;
        }

        openBatchModal(incidentIds);
    };

    const openDeviceModelModal = () => {
        const incidentIds = getSelectedIncidentIds();

        if (incidentIds.length === 0) {
            return;
        }

        if (typeof openBatchDeviceModelModal !== 'function') {
            console.error('Batch device model modal is unavailable.');

            return;
        }

        openBatchDeviceModelModal(incidentIds);
    };

    const handleToolbarClick = (event) => {
        if (event.target.closest('[data-batch-clear]')) {
            event.preventDefault();
            clearSelection();

            return;
        }

        if (event.target.closest('[data-batch-device-model-assign]')) {
            event.preventDefault();
            openDeviceModelModal();

            return;
        }

        if (event.target.closest('[data-batch-assign]')) {
            event.preventDefault();
            openAssignModal();
        }
    };

    pageRoot.addEventListener('click', handleToolbarClick);

    return {
        clearSelection,
        handleCheckboxChange,
        handleSelectAll,
        handleBatchResult,
        syncSession,
        updateToolbar,
        restoreRowState,
        restoreAllRowStates,
        getSelectedIncidentIds,
        destroy: () => {
            pageRoot.removeEventListener('click', handleToolbarClick);
        },
    };
};
