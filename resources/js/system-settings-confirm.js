export const initSystemSettingsConfirm = () => {
    const modalEl = document.querySelector('[data-system-settings-confirm-modal]');

    if (! modalEl || typeof bootstrap === 'undefined') {
        return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const messageEl = modalEl.querySelector('[data-system-settings-confirm-message]');
    const impactBlock = modalEl.querySelector('[data-system-settings-confirm-impact]');
    const impactText = modalEl.querySelector('[data-system-settings-confirm-impact-text]');
    const modulesList = modalEl.querySelector('[data-system-settings-confirm-modules]');
    const acceptButton = modalEl.querySelector('[data-system-settings-confirm-accept]');

    let pendingInput = null;
    let pendingPreviousChecked = false;

    const resetPending = () => {
        pendingInput = null;
        pendingPreviousChecked = false;
    };

    acceptButton?.addEventListener('click', () => {
        pendingInput = null;
        modal.hide();
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        if (pendingInput) {
            pendingInput.checked = pendingPreviousChecked;
            pendingInput.dispatchEvent(new Event('change', { bubbles: true }));
            resetPending();
        }
    });

    document.querySelectorAll('[data-system-settings-high-impact]').forEach((input) => {
        input.addEventListener('change', (event) => {
            const checkbox = event.target;

            if (checkbox.checked) {
                return;
            }

            checkbox.checked = true;
            pendingInput = checkbox;
            pendingPreviousChecked = true;

            const label = checkbox.dataset.settingLabel ?? 'this setting';
            const impact = checkbox.dataset.settingImpact ?? '';
            let modules = [];

            try {
                modules = JSON.parse(checkbox.dataset.settingModules ?? '[]');
            } catch {
                modules = [];
            }

            if (messageEl) {
                messageEl.textContent = `Are you sure you want to disable "${label}"? This is a high-impact change.`;
            }

            if (impactBlock && impactText) {
                impactBlock.hidden = ! impact;
                impactText.textContent = impact;
            }

            if (modulesList) {
                modulesList.innerHTML = '';
                modules.forEach((module) => {
                    const li = document.createElement('li');
                    li.textContent = module;
                    modulesList.appendChild(li);
                });
            }

            const onAccept = () => {
                if (pendingInput) {
                    pendingInput.checked = false;
                    pendingInput.dispatchEvent(new Event('change', { bubbles: true }));
                    resetPending();
                }
            };

            acceptButton?.addEventListener('click', onAccept, { once: true });
            modal.show();
        });
    });
};
