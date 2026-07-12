const ACTION_LABELS = {
    assign: 'Assign Engineer',
    escalate: 'Escalate Case',
    close: 'Close Case',
    reopen: 'Reopen Case',
};

const ACTION_PLACEHOLDERS = {
    assign: 'Reason for reassignment…',
    escalate: 'Explain why this requires escalation…',
    close: 'Closing summary…',
    reopen: 'Reason for reopening…',
};

const SUBMIT_ACCENT_CLASSES = [
    'workspace-action-submit--assign',
    'workspace-action-submit--escalate',
    'workspace-action-submit--close',
    'workspace-action-submit--reopen',
];

const autoGrowTextarea = (textarea) => {
    if (!(textarea instanceof HTMLTextAreaElement)) {
        return;
    }

    textarea.style.height = 'auto';
    textarea.style.height = `${textarea.scrollHeight}px`;
};

export const initActionDialog = (root = document) => {
    const form = root.querySelector('[data-workspace-action-dialog]');

    if (!form || form.dataset.workspaceActionDialogInitialized === 'true') {
        return;
    }

    const typeInput = form.querySelector('[data-workspace-action-type-input]');
    const cards = [...form.querySelectorAll('[data-workspace-action-card]')];
    const panels = [...form.querySelectorAll('[data-workspace-action-panel]')];
    const descriptions = [...form.querySelectorAll('[data-workspace-action-description]')];
    const notifyNotes = [...form.querySelectorAll('[data-workspace-action-notify-note]')];
    const submitButton = form.querySelector('[data-workspace-action-submit]');
    const remarkTextarea = form.querySelector('[data-workspace-action-remark]');
    const serialCheckbox = form.querySelector('#workspace_action_serial_unavailable');
    const referenceCheckbox = form.querySelector('#workspace_action_reference_unavailable');
    const serialDetail = form.querySelector('[data-workspace-exception-detail="serial"]');
    const referenceDetail = form.querySelector('[data-workspace-exception-detail="reference"]');
    const serialReason = form.querySelector('#workspace_action_serial_reason');
    const referenceReason = form.querySelector('#workspace_action_reference_reason');
    const serialCustom = form.querySelector('[data-workspace-exception-custom="serial"]');
    const referenceCustom = form.querySelector('[data-workspace-exception-custom="reference"]');
    const exceptionsDetails = form.querySelector('.workspace-action-exceptions');

    const togglePanelFields = (actionType, enabled) => {
        const panel = form.querySelector(`[data-workspace-action-panel="${actionType}"]`);

        if (!panel) {
            return;
        }

        panel.querySelectorAll('input, select, textarea').forEach((field) => {
            if (field === typeInput) {
                return;
            }

            field.disabled = !enabled;
        });
    };

    const updateSubmitButton = (actionType) => {
        if (!submitButton) {
            return;
        }

        submitButton.textContent = ACTION_LABELS[actionType] ?? 'Done';
        submitButton.classList.remove(...SUBMIT_ACCENT_CLASSES);
        submitButton.classList.add(`workspace-action-submit--${actionType}`);
    };

    const updateRemarkPlaceholder = (actionType) => {
        if (!remarkTextarea) {
            return;
        }

        remarkTextarea.placeholder = ACTION_PLACEHOLDERS[actionType] ?? 'Type a remark…';
    };

    const setAction = (actionType) => {
        if (!typeInput) {
            return;
        }

        typeInput.value = actionType;

        cards.forEach((card) => {
            const isActive = card.dataset.workspaceActionCard === actionType;
            card.classList.toggle('is-active', isActive);
            card.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            card.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        descriptions.forEach((description) => {
            const isActive = description.dataset.workspaceActionDescription === actionType;
            description.classList.toggle('d-none', !isActive);
        });

        notifyNotes.forEach((note) => {
            const isActive = note.dataset.workspaceActionNotifyNote === actionType;
            note.classList.toggle('d-none', !isActive);
        });

        updateSubmitButton(actionType);
        updateRemarkPlaceholder(actionType);

        panels.forEach((panel) => {
            const isActive = panel.dataset.workspaceActionPanel === actionType;
            panel.classList.toggle('d-none', !isActive);
            togglePanelFields(panel.dataset.workspaceActionPanel, isActive);
        });
    };

    const syncExceptionDetail = (checkbox, detail) => {
        if (!detail) {
            return;
        }

        detail.classList.toggle('d-none', !checkbox?.checked);
    };

    const syncExceptionCustom = (reasonSelect, customBlock) => {
        if (!customBlock || !reasonSelect) {
            return;
        }

        customBlock.classList.toggle('d-none', reasonSelect.value !== 'other');
    };

    const openExceptionsIfNeeded = () => {
        if (!exceptionsDetails) {
            return;
        }

        if (serialCheckbox?.checked || referenceCheckbox?.checked) {
            exceptionsDetails.open = true;
        }
    };

    cards.forEach((card) => {
        card.addEventListener('click', () => {
            setAction(card.dataset.workspaceActionCard);
        });

        card.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }

            event.preventDefault();
            setAction(card.dataset.workspaceActionCard);
        });
    });

    serialCheckbox?.addEventListener('change', () => {
        syncExceptionDetail(serialCheckbox, serialDetail);
        openExceptionsIfNeeded();
    });

    referenceCheckbox?.addEventListener('change', () => {
        syncExceptionDetail(referenceCheckbox, referenceDetail);
        openExceptionsIfNeeded();
    });

    serialReason?.addEventListener('change', () => {
        syncExceptionCustom(serialReason, serialCustom);
    });

    referenceReason?.addEventListener('change', () => {
        syncExceptionCustom(referenceReason, referenceCustom);
    });

    remarkTextarea?.addEventListener('input', () => {
        autoGrowTextarea(remarkTextarea);
    });

    openExceptionsIfNeeded();
    syncExceptionDetail(serialCheckbox, serialDetail);
    syncExceptionDetail(referenceCheckbox, referenceDetail);
    syncExceptionCustom(serialReason, serialCustom);
    syncExceptionCustom(referenceReason, referenceCustom);
    autoGrowTextarea(remarkTextarea);

    form.dataset.workspaceActionDialogInitialized = 'true';
};
