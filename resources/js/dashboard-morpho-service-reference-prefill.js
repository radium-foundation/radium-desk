import { getWorkspaceSession } from './workspace/session';

const PANEL_CLASS = 'morpho-service-reference-prefill-panel';

const formatValue = (value) => (value == null || value === '' ? '—' : String(value));

const findTransactionCell = (incidentId) => document.querySelector(
    `[data-inline-transaction="true"][data-incident-id="${incidentId}"]`,
);

const openInlineEditor = (cell) => {
    const trigger = cell.querySelector('.transaction-cell-trigger');
    const editor = cell.querySelector('.transaction-inline-editor');
    const input = cell.querySelector('.transaction-inline-input');
    const error = cell.querySelector('.transaction-inline-error');

    if (!editor || !input) {
        return false;
    }

    trigger?.classList.add('d-none');
    editor.classList.remove('d-none');

    if (error) {
        error.textContent = '';
    }

    input.classList.remove('is-invalid');
    input.focus();

    getWorkspaceSession().acquire('inline-transaction', {
        incidentId: Number(cell.dataset.incidentId),
    });

    return true;
};

const removePrefillPanel = (incidentId) => {
    const cell = findTransactionCell(incidentId);
    cell?.querySelector(`.${PANEL_CLASS}`)?.remove();
};

/**
 * @param {number} incidentId
 * @param {{ eligible?: boolean, rejection_reason?: string|null, oem_review?: Record<string, string|null>, suggested_transaction_id?: string|null, session_public_id?: string }} prefill
 */
export const presentMorphoServiceReferencePrefill = (incidentId, prefill) => {
    const cell = findTransactionCell(incidentId);
    if (!cell || !prefill) {
        return { presented: false, reason: 'no-transaction-cell' };
    }

    removePrefillPanel(incidentId);

    const panel = document.createElement('div');
    panel.className = `${PANEL_CLASS} small border rounded bg-light p-2 mt-1`;
    panel.setAttribute('data-morpho-sr-prefill', String(incidentId));
    panel.setAttribute('data-oem-session-public-id', prefill.session_public_id ?? '');

    const review = prefill.oem_review ?? {};
    const eligible = prefill.eligible === true;

    const suggestion = prefill.suggested_transaction_id?.trim();
    const suggestionBlock = suggestion
        ? `<p class="mb-2"><span class="fw-semibold">Suggested service reference:</span> <code>${suggestion}</code></p>`
        : '';

    panel.innerHTML = `
        <div class="fw-semibold">Service reference suggestion</div>
        ${suggestionBlock}
        <p class="text-muted mb-1 mb-sm-2">
            ${eligible
        ? (suggestion
            ? 'Review the OEM suggestion, open the editor, confirm the reference, then save.'
            : 'Review OEM values, enter the service reference, then save.')
        : (prefill.rejection_reason ?? 'This Morpho result cannot prefill the service reference workflow.')}
        </p>
        <dl class="mb-2 oem-handoff-draft-panel__dl">
            <dt>Whitelist status</dt><dd>${formatValue(review.whitelist_status)}</dd>
            <dt>Whitelist date</dt><dd>${formatValue(review.whitelist_date)}</dd>
            <dt>Expiry date</dt><dd>${formatValue(review.expiry_date)}</dd>
            <dt>Device validity</dt><dd>${formatValue(review.device_validity)}</dd>
            <dt>Device type</dt><dd>${formatValue(review.device_type)}</dd>
        </dl>
        ${eligible ? `
        <button type="button" class="btn btn-sm btn-outline-primary" data-morpho-sr-open-editor>
            Open service reference editor
        </button>
        <p class="text-muted mb-0 mt-1">Saving still requires your confirmation — Desk will not auto-submit.</p>
        ` : ''}
    `;

    cell.appendChild(panel);

    if (!eligible) {
        return { presented: true, eligible: false };
    }

    panel.querySelector('[data-morpho-sr-open-editor]')?.addEventListener('click', (event) => {
        event.stopPropagation();
        const opened = openInlineEditor(cell);
        const input = cell.querySelector('.transaction-inline-input');
        const suggestedRef = prefill.suggested_transaction_id?.trim();

        if (opened && input && suggestedRef) {
            input.value = suggestedRef;
        }
    });

    return { presented: true, eligible: true };
};

export const clearMorphoServiceReferencePrefill = removePrefillPanel;