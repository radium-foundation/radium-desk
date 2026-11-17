import { formatRdExpiryYmdForServiceReferenceInput } from './dashboard-oem-rd-expiry-prefill';
import { getOemInlineCompletionContext } from './dashboard-oem-inline-completion';
import { getWorkspaceSession } from './workspace/session';

export const OEM_INLINE_COMPLETION_READY = 'radium:oem-inline-completion-ready';

const isDeskDocumentVisible = () => document.visibilityState === 'visible';

const findRefTransactionCell = (incidentId) => {
    const row = document.getElementById(`service-case-row-${incidentId}`);
    if (!row) {
        return null;
    }

    return row.querySelector('.transaction-id-cell[data-inline-transaction="true"]');
};

const proposedDisplayFromContext = (oemContext) => {
    if (!oemContext?.proposedRdExpiryYmd) {
        return null;
    }

    return (
        oemContext.proposedRdExpiryDisplay
        ?? formatRdExpiryYmdForServiceReferenceInput(oemContext.proposedRdExpiryYmd)
    );
};

const inputHasUnrelatedUserText = (input, displayValue) => {
    const current = input.value ?? '';
    if (current.trim() === '') {
        return false;
    }

    if (current === displayValue) {
        return false;
    }

    if (input.dataset.oemRdPrefillValue === displayValue && current.startsWith(displayValue)) {
        return false;
    }

    return true;
};

/**
 * Open REF inline editor and prefill Mantra RD expiry as plain text at caret position 0.
 * Does nothing when the Desk document is hidden (operator still on OEM tab).
 */
export const tryPrefillOemServiceReferenceInput = (incidentId, { openEditor = true, focusInput = true } = {}) => {
    const id = Number(incidentId);
    if (!Number.isFinite(id) || id <= 0) {
        return false;
    }

    const oemContext = getOemInlineCompletionContext(id);
    const displayValue = proposedDisplayFromContext(oemContext);
    if (!displayValue) {
        return false;
    }

    const cell = findRefTransactionCell(id);
    if (!cell) {
        return false;
    }

    const trigger = cell.querySelector('.transaction-cell-trigger');
    const editor = cell.querySelector('.transaction-inline-editor');
    const input = cell.querySelector('.transaction-inline-input');
    if (!editor || !input) {
        return false;
    }

    if (inputHasUnrelatedUserText(input, displayValue)) {
        return false;
    }

    if (!isDeskDocumentVisible()) {
        return false;
    }

    if (openEditor) {
        trigger?.classList.add('d-none');
        editor.classList.remove('d-none');
        getWorkspaceSession().acquire('inline-transaction', { incidentId: id });
    }

    input.classList.remove('is-invalid');
    input.value = displayValue;
    input.dataset.oemRdPrefillValue = displayValue;

    if (focusInput && isDeskDocumentVisible()) {
        input.focus({ preventScroll: true });
        input.setSelectionRange(0, 0);
    }

    return true;
};

const pendingVisiblePrefillIncidents = new Set();

export const scheduleOemServiceReferencePrefill = (incidentId) => {
    const id = Number(incidentId);
    if (!Number.isFinite(id) || id <= 0) {
        return;
    }

    pendingVisiblePrefillIncidents.add(id);

    if (isDeskDocumentVisible()) {
        flushOemServiceReferencePrefillQueue();
    }
};

export const flushOemServiceReferencePrefillQueue = () => {
    if (!isDeskDocumentVisible()) {
        return;
    }

    const ids = [...pendingVisiblePrefillIncidents];
    pendingVisiblePrefillIncidents.clear();

    ids.forEach((incidentId) => {
        tryPrefillOemServiceReferenceInput(incidentId);
    });
};

export const initDashboardOemRefServicePrefill = () => {
    document.addEventListener('visibilitychange', () => {
        if (isDeskDocumentVisible()) {
            flushOemServiceReferencePrefillQueue();
        }
    });

    document.addEventListener(OEM_INLINE_COMPLETION_READY, (event) => {
        const incidentId = event?.detail?.incidentId;
        if (incidentId != null) {
            scheduleOemServiceReferencePrefill(incidentId);
        }
    });
};