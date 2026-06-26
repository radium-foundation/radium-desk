import { flushPendingDashboardRefresh } from '../live-dashboard';
import { getWorkspaceSession } from './session';

const MAX_CONCURRENT_SUBMISSIONS = 3;

const runWithConcurrency = async (items, limit, worker) => {
    if (items.length === 0) {
        return [];
    }

    const results = new Array(items.length);
    let nextIndex = 0;

    const runners = Array.from({ length: Math.min(limit, items.length) }, async () => {
        while (nextIndex < items.length) {
            const currentIndex = nextIndex;
            nextIndex += 1;
            results[currentIndex] = await worker(items[currentIndex], currentIndex);
        }
    });

    await Promise.all(runners);

    return results;
};

export const createBatchTransactionSession = ({
    card,
    csrfToken,
    replaceServiceCaseRow,
    showToast,
}) => {
    const session = getWorkspaceSession();

    const bulkBar = card.querySelector('[data-bulk-bar]');
    const bulkCount = card.querySelector('[data-bulk-count]');
    const batchProgress = card.querySelector('[data-batch-progress]');
    const batchCompleted = card.querySelector('[data-batch-completed]');
    const batchTotal = card.querySelector('[data-batch-total]');
    const clearButton = card.querySelector('[data-batch-clear]');
    const submitButton = card.querySelector('[data-batch-submit]');
    const selectAll = card.querySelector('[data-select-all]');

    let isSubmitting = false;
    let completedCount = 0;
    let totalCount = 0;
    const submittingIncidentIds = new Set();
    const activeSubmissionToken = { current: null };
    const selectedIncidentIds = new Set();
    const transactionValues = new Map();
    const rowBatchStatuses = new Map();

    const getRow = (incidentId) => document.getElementById(`service-case-row-${incidentId}`);

    const getCheckbox = (incidentId) => card.querySelector(`.service-case-select[value="${incidentId}"]`);

    const getSelectedIncidentIds = () => Array.from(selectedIncidentIds);

    const getTransactionCell = (incidentId) => getRow(incidentId)?.querySelector('[data-inline-transaction="true"]');

    const getBatchEditor = (incidentId) => getTransactionCell(incidentId)?.querySelector('[data-batch-transaction-editor]');

    const getBatchInput = (incidentId) => getTransactionCell(incidentId)?.querySelector('[data-batch-transaction-input]');

    const getBatchStatus = (incidentId) => getTransactionCell(incidentId)?.querySelector('[data-batch-status]');

    const hasPersistedTransactionValues = () => Array.from(selectedIncidentIds).some((incidentId) => {
        const value = transactionValues.get(incidentId) ?? getBatchInput(incidentId)?.value ?? '';

        return value.trim() !== '';
    });

    const persistSelectedTransactionValuesFromDom = () => {
        selectedIncidentIds.forEach((incidentId) => {
            const input = getBatchInput(incidentId);

            if (input) {
                transactionValues.set(incidentId, input.value);
            }
        });
    };

    const syncDomSelectionFromState = () => {
        card.querySelectorAll('.service-case-select').forEach((checkbox) => {
            checkbox.checked = selectedIncidentIds.has(Number(checkbox.value));
        });
    };

    const syncRowEditorVisibility = (incidentId) => {
        const checkbox = getCheckbox(incidentId);
        const cell = getTransactionCell(incidentId);

        if (!cell) {
            return;
        }

        const trigger = cell.querySelector('.transaction-cell-trigger');
        const inlineEditor = cell.querySelector('.transaction-inline-editor');
        const batchEditor = getBatchEditor(incidentId);
        const inlineOpen = inlineEditor && !inlineEditor.classList.contains('d-none');
        const isSelected = selectedIncidentIds.has(incidentId);

        if (checkbox) {
            checkbox.checked = isSelected;
        }

        if (isSelected) {
            trigger?.classList.add('d-none');
            inlineEditor?.classList.add('d-none');
            batchEditor?.classList.remove('d-none');

            return;
        }

        batchEditor?.classList.add('d-none');

        if (!inlineOpen) {
            trigger?.classList.remove('d-none');
        }
    };

    const syncAllRowEditors = () => {
        card.querySelectorAll('.service-case-select').forEach((checkbox) => {
            syncRowEditorVisibility(Number(checkbox.value));
        });
    };

    const syncSession = () => {
        const selected = getSelectedIncidentIds();
        const submitting = Array.from(submittingIncidentIds);
        const lockedIds = [...new Set([...selected, ...submitting])];

        if (lockedIds.length > 0 || hasPersistedTransactionValues()) {
            session.acquire('bulk-selection', { incidentIds: lockedIds });
        } else {
            session.release('bulk-selection');
        }

        if (submitting.length > 0) {
            session.acquire('batch-submit', { incidentIds: submitting });
        } else {
            session.release('batch-submit');
        }
    };

    const updateToolbar = () => {
        persistSelectedTransactionValuesFromDom();
        syncDomSelectionFromState();
        const count = selectedIncidentIds.size;

        bulkBar?.classList.toggle('d-none', count === 0 && !isSubmitting);

        if (bulkCount) {
            bulkCount.textContent = String(count);
        }

        batchProgress?.classList.toggle('d-none', !isSubmitting);

        if (batchCompleted) {
            batchCompleted.textContent = String(completedCount);
        }

        if (batchTotal) {
            batchTotal.textContent = String(totalCount);
        }

        const hasSubmittable = getSelectedIncidentIds().some((incidentId) => {
            const value = transactionValues.get(incidentId) ?? getBatchInput(incidentId)?.value ?? '';

            return value.trim() !== '';
        });

        if (submitButton) {
            submitButton.disabled = isSubmitting || count === 0 || !hasSubmittable;
        }

        if (clearButton) {
            clearButton.disabled = isSubmitting;
        }

        if (selectAll) {
            const selectable = card.querySelectorAll('.service-case-select');
            selectAll.checked = selectable.length > 0 && count === selectable.length;
            selectAll.indeterminate = count > 0 && count < selectable.length;
        }

        syncSession();
    };

    const setRowStatus = (incidentId, state, message = '') => {
        if (state === 'idle') {
            rowBatchStatuses.delete(incidentId);
        } else {
            rowBatchStatuses.set(incidentId, { state, message });
        }

        const statusEl = getBatchStatus(incidentId);
        const input = getBatchInput(incidentId);

        if (!statusEl) {
            return;
        }

        statusEl.className = 'batch-transaction-status small';
        statusEl.dataset.batchStatus = state;
        statusEl.textContent = '';

        switch (state) {
            case 'submitting':
                statusEl.innerHTML = '<span class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></span><span class="visually-hidden">Submitting</span>';
                input?.classList.remove('is-invalid');
                break;
            case 'success':
                statusEl.innerHTML = '<i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i><span class="visually-hidden">Saved</span>';
                input?.classList.remove('is-invalid');
                break;
            case 'validation':
                statusEl.textContent = message;
                statusEl.classList.add('text-danger');
                input?.classList.add('is-invalid');
                break;
            case 'failed':
                statusEl.textContent = message || 'Unable to save.';
                statusEl.classList.add('text-danger');
                input?.classList.add('is-invalid');
                break;
            default:
                break;
        }
    };

    const clearRowBatchState = (incidentId) => {
        transactionValues.delete(incidentId);
        rowBatchStatuses.delete(incidentId);

        const input = getBatchInput(incidentId);

        if (input) {
            input.value = '';
            input.disabled = false;
            input.classList.remove('is-invalid');
        }

        setRowStatus(incidentId, 'idle');
    };

    const restoreRowState = (incidentId) => {
        const normalizedIncidentId = Number(incidentId);
        const input = getBatchInput(normalizedIncidentId);
        const storedValue = transactionValues.get(normalizedIncidentId);

        if (input && storedValue !== undefined) {
            input.value = storedValue;
        }

        syncRowEditorVisibility(normalizedIncidentId);

        const storedStatus = rowBatchStatuses.get(normalizedIncidentId);

        if (storedStatus) {
            setRowStatus(normalizedIncidentId, storedStatus.state, storedStatus.message);
        }
    };

    const restoreAllRowStates = () => {
        card.querySelectorAll('.service-case-select').forEach((checkbox) => {
            restoreRowState(Number(checkbox.value));
        });

        updateToolbar();
    };

    const clearSelection = () => {
        if (isSubmitting) {
            return;
        }

        selectedIncidentIds.clear();
        transactionValues.clear();
        rowBatchStatuses.clear();

        card.querySelectorAll('.service-case-select').forEach((checkbox) => {
            checkbox.checked = false;
            clearRowBatchState(Number(checkbox.value));
        });

        if (selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        }

        syncAllRowEditors();
        updateToolbar();
    };

    const submitOne = async (incidentId, submissionToken) => {
        if (submissionToken !== activeSubmissionToken.current) {
            return { incidentId, ok: false, skipped: true };
        }

        const cell = getTransactionCell(incidentId);
        const input = getBatchInput(incidentId);
        const storeUrl = cell?.dataset.storeUrl;
        const transactionId = transactionValues.get(incidentId) ?? input?.value.trim() ?? '';

        if (!storeUrl || !input) {
            setRowStatus(incidentId, 'failed', 'Unable to save.');
            submittingIncidentIds.delete(incidentId);
            syncSession();

            return { incidentId, ok: false };
        }

        if (transactionId === '') {
            setRowStatus(incidentId, 'validation', 'Transaction ID is required.');
            submittingIncidentIds.delete(incidentId);
            syncSession();

            return { incidentId, ok: false };
        }

        submittingIncidentIds.add(incidentId);
        syncSession();
        setRowStatus(incidentId, 'submitting');
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
                    incident_id: incidentId,
                }),
            });

            const data = await response.json();

            if (submissionToken !== activeSubmissionToken.current) {
                input.disabled = false;
                submittingIncidentIds.delete(incidentId);
                syncSession();

                return { incidentId, ok: false, skipped: true };
            }

            if (!response.ok) {
                const message = data.errors?.transaction_id?.[0]
                    ?? data.message
                    ?? 'Unable to save transaction ID.';
                setRowStatus(incidentId, 'validation', message);
                input.disabled = false;
                submittingIncidentIds.delete(incidentId);
                syncSession();

                return { incidentId, ok: false };
            }

            setRowStatus(incidentId, 'success');

            selectedIncidentIds.delete(incidentId);
            transactionValues.delete(incidentId);
            rowBatchStatuses.delete(incidentId);

            if (data.row_html && data.incident_id) {
                replaceServiceCaseRow(data.incident_id, data.row_html);
            }

            submittingIncidentIds.delete(incidentId);
            syncSession();
            updateToolbar();

            return { incidentId, ok: true, message: data.message };
        } catch {
            if (submissionToken !== activeSubmissionToken.current) {
                input.disabled = false;
                submittingIncidentIds.delete(incidentId);
                syncSession();

                return { incidentId, ok: false, skipped: true };
            }

            setRowStatus(incidentId, 'failed', 'Unable to save.');
            input.disabled = false;
            submittingIncidentIds.delete(incidentId);
            syncSession();

            return { incidentId, ok: false };
        }
    };

    const submitBatch = async () => {
        if (isSubmitting) {
            return;
        }

        const incidentIds = getSelectedIncidentIds();

        if (incidentIds.length === 0) {
            return;
        }

        persistSelectedTransactionValuesFromDom();

        const submissionToken = Symbol('batch-submit');
        activeSubmissionToken.current = submissionToken;
        isSubmitting = true;
        completedCount = 0;
        totalCount = incidentIds.length;
        updateToolbar();

        const results = await runWithConcurrency(
            incidentIds,
            MAX_CONCURRENT_SUBMISSIONS,
            async (incidentId) => {
                const result = await submitOne(incidentId, submissionToken);

                if (!result.skipped) {
                    completedCount += 1;
                    updateToolbar();
                }

                return result;
            },
        );

        if (submissionToken !== activeSubmissionToken.current) {
            return;
        }

        isSubmitting = false;
        activeSubmissionToken.current = null;
        syncAllRowEditors();
        updateToolbar();

        const successes = results.filter((result) => result?.ok);
        const failures = results.filter((result) => result && !result.ok && !result.skipped);

        if (successes.length > 0) {
            showToast(
                successes.length === 1
                    ? (successes[0].message ?? 'Transaction ID saved.')
                    : `${successes.length} transaction IDs saved.`,
            );
        }

        if (failures.length > 0 && successes.length === 0) {
            showToast('Some transaction IDs could not be saved.', 'danger');
        }

        if (!session.isActive()) {
            await flushPendingDashboardRefresh();
        }
    };

    const handleCheckboxChange = (checkbox) => {
        const incidentId = Number(checkbox.value);

        if (checkbox.checked) {
            selectedIncidentIds.add(incidentId);
        } else {
            selectedIncidentIds.delete(incidentId);
            clearRowBatchState(incidentId);
        }

        syncRowEditorVisibility(incidentId);
        updateToolbar();
    };

    const handleSelectAll = (checked) => {
        card.querySelectorAll('.service-case-select').forEach((checkbox) => {
            const incidentId = Number(checkbox.value);

            if (checked) {
                selectedIncidentIds.add(incidentId);
            } else {
                selectedIncidentIds.delete(incidentId);
                clearRowBatchState(incidentId);
            }
        });

        syncAllRowEditors();
        updateToolbar();
    };

    const handleBatchInput = (input) => {
        const row = input.closest('tr[id^="service-case-row-"]');
        const incidentId = Number(row?.id.replace('service-case-row-', ''));

        if (!Number.isNaN(incidentId)) {
            transactionValues.set(incidentId, input.value);
        }

        updateToolbar();
    };

    clearButton?.addEventListener('click', clearSelection);
    submitButton?.addEventListener('click', submitBatch);

    card.addEventListener('input', (event) => {
        if (event.target.matches('[data-batch-transaction-input]')) {
            handleBatchInput(event.target);
        }
    });

    return {
        clearSelection,
        handleCheckboxChange,
        handleSelectAll,
        handleBatchInput,
        submitBatch,
        syncSession,
        updateToolbar,
        restoreRowState,
        restoreAllRowStates,
        getSelectedIncidentIds,
        getSubmittingIncidentIds: () => Array.from(submittingIncidentIds),
        isSubmitting: () => isSubmitting,
    };
};

export { MAX_CONCURRENT_SUBMISSIONS };
