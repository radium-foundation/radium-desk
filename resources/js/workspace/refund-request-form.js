const OTHER_REASON = 'other';
const PARTIAL_EPSILON = 0.001;

const resolveForm = (root) => {
    if (root instanceof HTMLFormElement && root.matches('[data-refund-request-form], [data-workspace-action-form="refund-request"]')) {
        return root;
    }

    if (root instanceof HTMLElement && root.matches('[data-refund-request-form]')) {
        return root;
    }

    return root?.querySelector?.('[data-refund-request-form], [data-workspace-action-form="refund-request"]')
        ?? null;
};

const parseAmount = (value) => {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const amount = Number.parseFloat(String(value));

    return Number.isFinite(amount) ? amount : null;
};

const resolveMaximum = (form, amountInput) => {
    const fromInput = parseAmount(amountInput?.dataset?.refundMaximum);

    if (fromInput !== null) {
        return fromInput;
    }

    return parseAmount(form.querySelector('[data-refund-maximum-display]')?.dataset?.refundMaximumDisplay);
};

const setDisabled = (element, disabled) => {
    if (!(element instanceof HTMLInputElement
        || element instanceof HTMLSelectElement
        || element instanceof HTMLTextAreaElement)) {
        return;
    }

    element.disabled = disabled;
};

const syncPartialFields = (form) => {
    const amountInput = form.querySelector('[data-refund-amount-input]');
    const partialFields = form.querySelector('[data-refund-partial-fields]');
    const notesFields = form.querySelector('[data-refund-partial-notes-fields]');
    const reasonSelect = form.querySelector('[data-refund-partial-reason]');
    const notesInput = form.querySelector('[data-refund-partial-notes]');

    if (!(amountInput instanceof HTMLInputElement) || !(partialFields instanceof HTMLElement)) {
        return;
    }

    const amount = parseAmount(amountInput.value);
    const maximum = resolveMaximum(form, amountInput);
    const isPartial = amount !== null
        && maximum !== null
        && amount < (maximum - PARTIAL_EPSILON);
    const isOther = isPartial
        && reasonSelect instanceof HTMLSelectElement
        && reasonSelect.value === OTHER_REASON;

    partialFields.classList.toggle('d-none', !isPartial);
    setDisabled(reasonSelect, !isPartial);

    if (notesFields instanceof HTMLElement) {
        notesFields.classList.toggle('d-none', !isOther);
    }

    setDisabled(notesInput, !isOther);
};

export const initRefundRequestForm = (root = document) => {
    const form = resolveForm(root);

    if (!form || form.dataset.refundPartialFieldsInitialized === 'true') {
        return form;
    }

    form.dataset.refundPartialFieldsInitialized = 'true';

    const amountInput = form.querySelector('[data-refund-amount-input]');
    const reasonSelect = form.querySelector('[data-refund-partial-reason]');

    const sync = () => syncPartialFields(form);

    amountInput?.addEventListener('input', sync);
    amountInput?.addEventListener('change', sync);
    reasonSelect?.addEventListener('change', sync);

    sync();

    return form;
};
