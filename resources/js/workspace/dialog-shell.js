const SNAPSHOT_IGNORED_FIELDS = new Set([
    '_token',
    'workspace_context',
    'action_type',
]);

const getNamedFormElements = (form, { includeDisabled = false } = {}) => {
    const elements = form.querySelectorAll('input, select, textarea');

    return Array.from(elements).filter((element) => {
        if (!(element instanceof HTMLInputElement
            || element instanceof HTMLSelectElement
            || element instanceof HTMLTextAreaElement)) {
            return false;
        }

        if (element.name === '') {
            return false;
        }

        if (SNAPSHOT_IGNORED_FIELDS.has(element.name)) {
            return false;
        }

        return includeDisabled || !element.disabled;
    });
};

const shouldIncludeDisabledFields = (form) => form.matches('[data-workspace-action-dialog]');

export const getFormSnapshot = (form) => {
    const includeDisabled = shouldIncludeDisabledFields(form);
    const snapshot = {};

    getNamedFormElements(form, { includeDisabled }).forEach((element) => {
        if (element instanceof HTMLInputElement && element.type === 'checkbox') {
            snapshot[element.name] = element.checked ? '1' : '0';

            return;
        }

        if (element instanceof HTMLInputElement && element.type === 'radio') {
            if (element.checked) {
                snapshot[element.name] = element.value;
            }

            return;
        }

        snapshot[element.name] = element.value;
    });

    return JSON.stringify(snapshot);
};

export const allowWorkspaceModalClose = (host) => {
    if (host) {
        host.dataset.workspaceAllowClose = 'true';
    }
};

const isDiscardConfirmVisible = (host) => {
    const confirm = host?.querySelector('.workspace-discard-confirm');

    return Boolean(confirm?.classList.contains('is-visible'));
};

let discardConfirmController = null;

const dismissDiscardConfirm = (host) => {
    discardConfirmController?.abort();
    discardConfirmController = null;

    const confirm = host?.querySelector('.workspace-discard-confirm');

    if (!confirm) {
        return;
    }

    confirm.hidden = true;
    confirm.classList.remove('is-visible');
};

const showDiscardConfirm = (host, onDiscard) => {
    if (isDiscardConfirmVisible(host)) {
        return;
    }

    const confirm = host?.querySelector('.workspace-discard-confirm');

    if (!confirm) {
        onDiscard();

        return;
    }

    discardConfirmController?.abort();
    discardConfirmController = new AbortController();

    const cancelButton = confirm.querySelector('[data-workspace-discard-cancel]');
    const discardButton = confirm.querySelector('[data-workspace-discard-apply]');

    const cleanup = () => {
        discardConfirmController?.abort();
        discardConfirmController = null;
        confirm.classList.remove('is-visible');
        confirm.hidden = true;
    };

    const handleCancel = () => {
        cleanup();
    };

    const handleDiscard = () => {
        cleanup();
        onDiscard();
    };

    const { signal } = discardConfirmController;

    cancelButton?.addEventListener('click', handleCancel, { signal });
    discardButton?.addEventListener('click', handleDiscard, { signal });

    confirm.hidden = false;
    confirm.classList.add('is-visible');
    discardButton?.focus();
};

let activeDialogShell = null;

export const initWorkspaceDialogShell = (host, modalContent) => {
    activeDialogShell?.abort();
    dismissDiscardConfirm(host);

    if (!host || !modalContent) {
        activeDialogShell = null;

        return;
    }

    const form = modalContent.querySelector('[data-workspace-action-form]');

    if (!form) {
        activeDialogShell = null;

        return;
    }

    const controller = new AbortController();
    activeDialogShell = controller;

    const initialSnapshot = getFormSnapshot(form);

    const isDirty = () => {
        const currentForm = host.querySelector('[data-workspace-action-form]');

        if (!currentForm) {
            return false;
        }

        return getFormSnapshot(currentForm) !== initialSnapshot;
    };

    const handleHide = (event) => {
        if (host.dataset.workspaceAllowClose === 'true') {
            delete host.dataset.workspaceAllowClose;

            return;
        }

        if (isDiscardConfirmVisible(host)) {
            event.preventDefault();
            dismissDiscardConfirm(host);

            return;
        }

        if (!isDirty()) {
            return;
        }

        event.preventDefault();
        showDiscardConfirm(host, () => {
            allowWorkspaceModalClose(host);
            const modal = window.bootstrap?.Modal?.getInstance(host);

            modal?.hide();
        });
    };

    host.addEventListener('hide.bs.modal', handleHide, { signal: controller.signal });

    host.addEventListener('hidden.bs.modal', () => {
        dismissDiscardConfirm(host);

        if (activeDialogShell === controller) {
            controller.abort();
            activeDialogShell = null;
        }
    }, { signal: controller.signal });
};

export const elevateWorkspaceModalBackdrop = () => {
    document.querySelectorAll('.modal-backdrop.show').forEach((backdrop) => {
        backdrop.classList.add('workspace-modal-backdrop');
    });
};

export const resetWorkspaceModalBackdrop = () => {
    document.querySelectorAll('.modal-backdrop.workspace-modal-backdrop').forEach((backdrop) => {
        backdrop.classList.remove('workspace-modal-backdrop');
    });
};
