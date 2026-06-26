const HOST_BUSY_CLASS = 'workspace-host-busy';
const CONTENT_LOADING_CLASS = 'pe-none';
const SUBMIT_BUSY_ATTR = 'data-workspace-submit-busy';

export const WORKSPACE_LOADING_HTML = `
<div class="p-4 text-center text-muted" data-workspace-loading aria-busy="true">
    <div class="spinner-border spinner-border-sm me-2" role="status">
        <span class="visually-hidden">Loading…</span>
    </div>
    Loading…
</div>`;

export const createBusyStateManager = (host, modalContentSelector = '[data-workspace-modal-content]') => {
    const reasons = new Set();
    let activeSubmitButton = null;
    let submitButtonOriginalHtml = null;

    const getModalContent = () => host?.querySelector(modalContentSelector);

    const applyHostState = () => {
        if (!host) {
            return;
        }

        const busy = reasons.size > 0;
        host.classList.toggle(HOST_BUSY_CLASS, busy);
        host.toggleAttribute('aria-busy', busy);

        const modalContent = getModalContent();

        if (modalContent) {
            modalContent.classList.toggle(CONTENT_LOADING_CLASS, reasons.has('loading'));
        }
    };

    const setSubmitBusy = (form) => {
        const submitButton = form?.querySelector('[type="submit"]');

        if (!submitButton || submitButton.hasAttribute(SUBMIT_BUSY_ATTR)) {
            return;
        }

        submitButtonOriginalHtml = submitButton.innerHTML;
        activeSubmitButton = submitButton;
        submitButton.disabled = true;
        submitButton.setAttribute(SUBMIT_BUSY_ATTR, 'true');
        submitButton.innerHTML = `
            <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
            ${submitButton.dataset.workspaceSubmitLabel ?? 'Working…'}
        `;
    };

    const clearSubmitBusy = (form = null) => {
        const submitButton = form?.querySelector('[type="submit"]') ?? activeSubmitButton;

        if (!submitButton?.hasAttribute(SUBMIT_BUSY_ATTR)) {
            return;
        }

        submitButton.disabled = false;
        submitButton.innerHTML = submitButtonOriginalHtml ?? submitButton.innerHTML;
        submitButton.removeAttribute(SUBMIT_BUSY_ATTR);
        activeSubmitButton = null;
        submitButtonOriginalHtml = null;
    };

    const setBusy = (reason = 'request', form = null) => {
        reasons.add(reason);
        applyHostState();

        if (reason === 'submit' && form) {
            setSubmitBusy(form);
        }
    };

    const clearBusy = (reason = 'request', form = null) => {
        if (reason === 'submit') {
            clearSubmitBusy(form);
        }

        reasons.delete(reason);
        applyHostState();
    };

    const isBusy = (reason = null) => {
        if (reason) {
            return reasons.has(reason);
        }

        return reasons.size > 0;
    };

    return {
        setBusy,
        clearBusy,
        isBusy,
    };
};
