export const initActionDialog = (root = document) => {
    const form = root.querySelector('[data-workspace-action-dialog]');

    if (!form || form.dataset.workspaceActionDialogInitialized === 'true') {
        return;
    }

    const typeInput = form.querySelector('[data-workspace-action-type-input]');
    const cards = [...form.querySelectorAll('[data-workspace-action-card]')];
    const panels = [...form.querySelectorAll('[data-workspace-action-panel]')];
    const exceptionFields = form.querySelector('[data-workspace-exception-fields]');
    const exceptionCustom = form.querySelector('[data-workspace-exception-custom]');
    const exceptionReason = form.querySelector('#workspace_action_exception_reason');
    const serialCheckbox = form.querySelector('#workspace_action_serial_unavailable');
    const referenceCheckbox = form.querySelector('#workspace_action_reference_unavailable');

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

        panels.forEach((panel) => {
            const isActive = panel.dataset.workspaceActionPanel === actionType;
            panel.classList.toggle('d-none', !isActive);
            togglePanelFields(panel.dataset.workspaceActionPanel, isActive);
        });
    };

    const syncExceptionFields = () => {
        if (!exceptionFields) {
            return;
        }

        const show = Boolean(serialCheckbox?.checked || referenceCheckbox?.checked);
        exceptionFields.classList.toggle('d-none', !show);
    };

    const syncExceptionCustom = () => {
        if (!exceptionCustom || !exceptionReason) {
            return;
        }

        exceptionCustom.classList.toggle('d-none', exceptionReason.value !== 'other');
    };

    cards.forEach((card) => {
        card.addEventListener('click', () => {
            setAction(card.dataset.workspaceActionCard);
        });
    });

    serialCheckbox?.addEventListener('change', syncExceptionFields);
    referenceCheckbox?.addEventListener('change', syncExceptionFields);
    exceptionReason?.addEventListener('change', syncExceptionCustom);

    syncExceptionFields();
    syncExceptionCustom();

    form.dataset.workspaceActionDialogInitialized = 'true';
};
