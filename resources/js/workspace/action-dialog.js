export const initActionDialog = (root = document) => {
    const form = root.querySelector('[data-workspace-action-dialog]');

    if (!form || form.dataset.workspaceActionDialogInitialized === 'true') {
        return;
    }

    const typeInput = form.querySelector('[data-workspace-action-type-input]');
    const cards = [...form.querySelectorAll('[data-workspace-action-card]')];
    const panels = [...form.querySelectorAll('[data-workspace-action-panel]')];
    const escalateButton = form.querySelector('[data-workspace-action-escalate]');
    const submitButton = form.querySelector('[data-workspace-action-submit]');
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

    const setAction = (actionType) => {
        if (!typeInput) {
            return;
        }

        typeInput.value = actionType;

        cards.forEach((card) => {
            const isActive = card.dataset.workspaceActionCard === actionType;
            card.classList.toggle('is-active', isActive);
            card.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });

        if (escalateButton) {
            const isEscalate = actionType === 'escalate';
            escalateButton.classList.toggle('is-active', isEscalate);
            escalateButton.setAttribute('aria-pressed', isEscalate ? 'true' : 'false');
        }

        if (submitButton) {
            const isEscalate = actionType === 'escalate';
            submitButton.textContent = isEscalate ? 'Escalate' : 'Done';
            submitButton.classList.toggle('btn-primary', ! isEscalate);
            submitButton.classList.toggle('btn-outline-secondary', isEscalate);
        }

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
    });

    escalateButton?.addEventListener('click', () => {
        setAction('escalate');
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

    openExceptionsIfNeeded();
    syncExceptionDetail(serialCheckbox, serialDetail);
    syncExceptionDetail(referenceCheckbox, referenceDetail);
    syncExceptionCustom(serialReason, serialCustom);
    syncExceptionCustom(referenceReason, referenceCustom);

    form.dataset.workspaceActionDialogInitialized = 'true';
};
