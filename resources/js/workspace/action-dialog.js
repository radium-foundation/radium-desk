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

const REMARK_LABELS = {
    assign: 'Remark',
    escalate: 'Remark',
    close: 'Closing Summary',
    reopen: 'Remark',
};

const SUBMIT_ACCENT_CLASSES = [
    'workspace-action-submit--assign',
    'workspace-action-submit--escalate',
    'workspace-action-submit--close',
    'workspace-action-submit--reopen',
];

const CLOSE_REASON_FIELDS = {
    issue_resolved: ['resolution_type'],
    customer_not_responding: ['contact_attempt', 'attempts'],
    customer_cancelled: [],
    reference_number_pending: ['expected_from', 'expected_date'],
    serial_number_pending: ['expected_from', 'expected_date'],
    warranty_rejected: [],
    replacement_issued: ['replacement_order_id'],
    payment_collected_offline: [],
    duplicate_case: ['existing_case_id'],
    approved_by_admin: ['approval_reference'],
    other: [],
};

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
    const remarkLabel = form.querySelector('[data-workspace-action-remark-label]');
    const closeReasonSelect = form.querySelector('[data-workspace-close-reason]');
    const closeNotificationFieldset = form.querySelector('[data-workspace-close-notification]');
    const closeFieldGroups = [...form.querySelectorAll('[data-workspace-close-field-group]')];

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

    const updateRemarkLabel = (actionType) => {
        if (!remarkLabel) {
            return;
        }

        const label = REMARK_LABELS[actionType] ?? 'Remark';
        remarkLabel.innerHTML = `${label} <span class="text-danger">*</span>`;
    };

    const syncCloseReasonFields = () => {
        if (!closeReasonSelect) {
            return;
        }

        const reason = closeReasonSelect.value;
        const visibleFields = new Set(CLOSE_REASON_FIELDS[reason] ?? []);

        closeFieldGroups.forEach((group) => {
            const fieldName = group.dataset.workspaceCloseFieldGroup;
            group.classList.toggle('d-none', !visibleFields.has(fieldName));
        });

        if (closeNotificationFieldset) {
            const selectedOption = closeReasonSelect.selectedOptions[0];
            const showsNotification = selectedOption?.dataset.showsNotification === '1';
            closeNotificationFieldset.classList.toggle('d-none', !showsNotification);

            closeNotificationFieldset.querySelectorAll('input[type="radio"]').forEach((input) => {
                input.disabled = !showsNotification;
            });

            if (!showsNotification) {
                const noOption = closeNotificationFieldset.querySelector('input[value="no"]');
                if (noOption) {
                    noOption.checked = true;
                }
            }
        }
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
        updateRemarkLabel(actionType);

        panels.forEach((panel) => {
            const isActive = panel.dataset.workspaceActionPanel === actionType;
            panel.classList.toggle('d-none', !isActive);
            togglePanelFields(panel.dataset.workspaceActionPanel, isActive);
        });

        if (actionType === 'close') {
            syncCloseReasonFields();
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

    closeReasonSelect?.addEventListener('change', syncCloseReasonFields);

    remarkTextarea?.addEventListener('input', () => {
        autoGrowTextarea(remarkTextarea);
    });

    syncCloseReasonFields();
    autoGrowTextarea(remarkTextarea);

    form.dataset.workspaceActionDialogInitialized = 'true';
};
