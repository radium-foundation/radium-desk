import { getWorkspaceSession } from './session';

export const createBatchTransactionSession = ({
    card,
    openBatchModal,
}) => {
    const session = getWorkspaceSession();

    const bulkBar = card.querySelector('[data-bulk-bar]');
    const bulkCount = card.querySelector('[data-bulk-count]');
    const clearButton = card.querySelector('[data-batch-clear]');
    const assignButton = card.querySelector('[data-batch-assign]');
    const selectAll = card.querySelector('[data-select-all]');

    const selectedIncidentIds = new Set();

    const getSelectedIncidentIds = () => Array.from(selectedIncidentIds);

    const syncDomSelectionFromState = () => {
        card.querySelectorAll('.service-case-select').forEach((checkbox) => {
            checkbox.checked = selectedIncidentIds.has(Number(checkbox.value));
        });
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

        bulkBar?.classList.toggle('d-none', count === 0);

        if (bulkCount) {
            bulkCount.textContent = String(count);
        }

        if (assignButton) {
            assignButton.disabled = count === 0;
        }

        if (clearButton) {
            clearButton.disabled = count === 0;
        }

        if (selectAll) {
            const selectable = card.querySelectorAll('.service-case-select');
            selectAll.checked = selectable.length > 0 && count === selectable.length;
            selectAll.indeterminate = count > 0 && count < selectable.length;
        }

        syncSession();
    };

    const restoreRowState = (incidentId) => {
        const normalizedIncidentId = Number(incidentId);
        const checkbox = card.querySelector(`.service-case-select[value="${normalizedIncidentId}"]`);

        if (checkbox) {
            checkbox.checked = selectedIncidentIds.has(normalizedIncidentId);
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
        });

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
        card.querySelectorAll('.service-case-select').forEach((checkbox) => {
            const incidentId = Number(checkbox.value);

            if (checked) {
                selectedIncidentIds.add(incidentId);
            } else {
                selectedIncidentIds.delete(incidentId);
            }
        });

        updateToolbar();
    };

    const handleBatchResult = (succeededIncidentIds = [], failedIncidents = []) => {
        succeededIncidentIds.forEach((incidentId) => {
            selectedIncidentIds.delete(Number(incidentId));
        });

        failedIncidents.forEach(({ incident_id: incidentId }) => {
            selectedIncidentIds.add(Number(incidentId));
        });

        updateToolbar();
    };

    const openAssignModal = () => {
        const incidentIds = getSelectedIncidentIds();

        if (incidentIds.length === 0 || !openBatchModal) {
            return;
        }

        openBatchModal(incidentIds);
    };

    clearButton?.addEventListener('click', clearSelection);
    assignButton?.addEventListener('click', openAssignModal);

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
    };
};
