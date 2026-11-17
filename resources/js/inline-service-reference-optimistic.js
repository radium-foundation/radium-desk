const escapeAttr = (value) => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('"', '&quot;')
    .replaceAll('<', '&lt;');

const escapeText = (value) => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;');

export const bumpInlineSaveGeneration = (cell) => {
    const next = (Number(cell.dataset.saveGeneration) || 0) + 1;
    cell.dataset.saveGeneration = String(next);

    return next;
};

export const isInlineSaveGenerationCurrent = (cell, generation) => (
    Number(cell.dataset.saveGeneration) === generation
);

/**
 * Close the inline editor and show the intended reference immediately (non-blocking).
 */
export const showOptimisticPendingReference = (cell, transactionId) => {
    const editor = cell.querySelector('.transaction-inline-editor');
    const trigger = cell.querySelector('.transaction-cell-trigger');
    const input = cell.querySelector('.transaction-inline-input');
    const error = cell.querySelector('.transaction-inline-error');

    editor?.classList.add('d-none');
    trigger?.classList.remove('d-none');
    input?.classList.remove('is-invalid');

    if (error) {
        error.textContent = '';
    }

    if (!trigger) {
        return;
    }

    if (!trigger.dataset.defaultHtml) {
        trigger.dataset.defaultHtml = trigger.innerHTML;
    }

    trigger.classList.add('transaction-cell-trigger--pending');
    trigger.setAttribute('aria-busy', 'true');
    trigger.setAttribute('aria-label', `Saving service reference ${transactionId}`);
    trigger.disabled = true;
    trigger.innerHTML = `
        <span class="transaction-pending-value text-truncate d-inline-block" title="${escapeAttr(transactionId)}">${escapeText(transactionId)}</span>
        <span class="spinner-border spinner-border-sm ms-1" role="status" aria-hidden="true"></span>
    `;

    cell.dataset.pendingTransactionId = transactionId;
};

export const revertOptimisticPendingReference = (cell, { reopenEditor = true, transactionId = null } = {}) => {
    const editor = cell.querySelector('.transaction-inline-editor');
    const trigger = cell.querySelector('.transaction-cell-trigger');
    const input = cell.querySelector('.transaction-inline-input');
    const failedValue = transactionId ?? cell.dataset.pendingTransactionId ?? input?.value ?? '';

    if (trigger?.dataset.defaultHtml) {
        trigger.innerHTML = trigger.dataset.defaultHtml;
        delete trigger.dataset.defaultHtml;
    }

    trigger?.classList.remove('transaction-cell-trigger--pending');
    trigger?.removeAttribute('aria-busy');
    trigger?.setAttribute('aria-label', 'Add service reference');

    if (trigger) {
        trigger.disabled = false;
    }

    delete cell.dataset.pendingTransactionId;

    if (!reopenEditor) {
        return;
    }

    trigger?.classList.add('d-none');
    editor?.classList.remove('d-none');

    if (input) {
        input.value = failedValue;
        input.disabled = false;
        input.focus();
    }
};