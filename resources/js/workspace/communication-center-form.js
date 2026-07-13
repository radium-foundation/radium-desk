const parseConfig = (form) => {
    const configElement = form.querySelector('[data-communication-center-config]');

    if (!configElement) {
        return null;
    }

    try {
        return JSON.parse(configElement.textContent ?? '{}');
    } catch {
        return null;
    }
};

const renderChannelList = (form, channels) => {
    const list = form.querySelector('[data-communication-center-channel-list]');

    if (!list) {
        return;
    }

    list.innerHTML = (channels ?? []).map((channel) => {
        if (channel.available) {
            return `
                <li class="request-serial-dialog-channel-item">
                    <span class="request-serial-dialog-channel-badge request-serial-dialog-channel-badge--available">
                        ✓ ${channel.label} available
                    </span>
                </li>
            `;
        }

        return `
            <li class="request-serial-dialog-channel-item">
                <span class="request-serial-dialog-channel-badge request-serial-dialog-channel-badge--unavailable">
                    ⚠ ${channel.label} unavailable
                </span>
            </li>
        `;
    }).join('');
};

const renderTargetOptions = (targetSelect, targets, selectedTarget) => {
    targetSelect.innerHTML = (targets ?? []).map((target) => `
        <option value="${target.value}"${String(target.value) === String(selectedTarget) ? ' selected' : ''}>
            ${target.label}
        </option>
    `).join('');

    targetSelect.disabled = (targets ?? []).length === 0;
};

const applyActionSelection = (form, config, actionKey) => {
    const actionSelect = form.querySelector('[data-communication-center-action]');
    const targetSelect = form.querySelector('[data-communication-center-target]');
    const targetLabel = form.querySelector('[data-communication-center-target-label]');
    const submitButton = form.querySelector('[data-communication-center-submit]');

    if (!actionSelect || !targetSelect || !targetLabel || !submitButton) {
        return;
    }

    const targets = config.targetsByAction?.[actionKey] ?? [];
    const defaultTarget = config.defaultTargets?.[actionKey] ?? null;
    const selectedTarget = defaultTarget ?? targets[0]?.value ?? null;

    form.action = config.actionUrls?.[actionKey] ?? form.action;
    targetLabel.textContent = config.targetGroupLabels?.[actionKey] ?? 'Target';
    renderTargetOptions(targetSelect, targets, selectedTarget);
    renderChannelList(form, config.channelAvailability?.[actionKey] ?? []);
    submitButton.disabled = ! (config.canSendByAction?.[actionKey] ?? false);
};

export const initCommunicationCenterForm = (root = document) => {
    const form = root.querySelector('[data-communication-center-form]');

    if (!form) {
        return;
    }

    const config = parseConfig(form);

    if (!config) {
        return;
    }

    const actionSelect = form.querySelector('[data-communication-center-action]');

    if (!actionSelect) {
        return;
    }

    actionSelect.addEventListener('change', () => {
        applyActionSelection(form, config, actionSelect.value);
    });
};
