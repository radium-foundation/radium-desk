import { getWorkspaceSession } from './session';

export const createBatchTransactionSession = ({
    card,
    pageRoot = document,
    openBatchModal,
}) => {
    const session = getWorkspaceSession();

    const selectedIncidentIds = new Set();

    const getBulkBar = () => pageRoot.querySelector('[data-bulk-bar]');
    const getBulkCount = () => pageRoot.querySelector('[data-bulk-count]');
    const getIdleHint = () => pageRoot.querySelector('[data-bulk-idle-hint]');
    const getSelectedLabel = () => pageRoot.querySelector('[data-bulk-selected-label]');
    const getAssignButton = () => pageRoot.querySelector('[data-batch-assign]');
    const getClearButton = () => pageRoot.querySelector('[data-batch-clear]');
    const getSelectAll = () => card.querySelector('[data-select-all]');

    const getSelectedIncidentIds = () => Array.from(selectedIncidentIds);

    const getRow = (incidentId) => document.getElementById(`service-case-row-${incidentId}`);

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

        if (clearButton) {
            clearButton.disabled = ! isActive;
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

    const handleToolbarClick = (event) => {
        if (event.target.closest('[data-batch-clear]')) {
            event.preventDefault();
            clearSelection();

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
