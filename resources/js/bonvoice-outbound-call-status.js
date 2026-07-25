const LIFECYCLE_LABELS = {
    calling: 'Calling...',
    ringing: 'Ringing...',
    answered: 'Connected',
    busy: 'Busy',
    no_answer: 'No Answer',
    failed: 'Failed',
    cancelled: 'Cancelled',
    completed: 'Completed',
};

const TERMINAL_STATUSES = new Set(['busy', 'no_answer', 'failed', 'cancelled', 'completed']);

export const lifecycleStatusLabel = (lifecycleStatus) => LIFECYCLE_LABELS[lifecycleStatus] ?? null;

export const isTerminalLifecycleStatus = (lifecycleStatus) => TERMINAL_STATUSES.has(lifecycleStatus);

let activeTracker = null;
let boundChannel = null;
let channelHandler = null;

const resetButton = (button) => {
    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    const label = button.querySelector('[data-bonvoice-call-status-label]');

    if (label instanceof HTMLElement) {
        label.textContent = button.dataset.bonvoiceDefaultLabel ?? 'Call';
    }

    button.classList.remove('is-outbound-calling');
    button.removeAttribute('aria-live');
    button.disabled = false;
};

const applyLifecycleToButton = (button, lifecycleStatus) => {
    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    const label = lifecycleStatusLabel(lifecycleStatus);

    if (!label) {
        return;
    }

    const labelNode = button.querySelector('[data-bonvoice-call-status-label]');

    if (labelNode instanceof HTMLElement) {
        labelNode.textContent = label;
    }

    button.classList.add('is-outbound-calling');
    button.setAttribute('aria-live', 'polite');
    button.disabled = true;
};

const stopTracking = () => {
    if (activeTracker?.resetTimeoutId) {
        window.clearTimeout(activeTracker.resetTimeoutId);
    }

    if (activeTracker?.button) {
        resetButton(activeTracker.button);
    }

    activeTracker = null;
};

const scheduleTerminalReset = (button, delayMs = 2500) => {
    const resetTimeoutId = window.setTimeout(() => {
        if (activeTracker?.button === button) {
            stopTracking();
        }
    }, delayMs);

    if (activeTracker) {
        activeTracker.resetTimeoutId = resetTimeoutId;
    }
};

const applyLifecycleUpdate = (call) => {
    if (!activeTracker || call?.event_id !== activeTracker.eventId) {
        return;
    }

    const lifecycleStatus = call?.lifecycle_status;

    if (!lifecycleStatus || !lifecycleStatusLabel(lifecycleStatus)) {
        return;
    }

    applyLifecycleToButton(activeTracker.button, lifecycleStatus);

    if (call.terminal === true || isTerminalLifecycleStatus(lifecycleStatus)) {
        scheduleTerminalReset(activeTracker.button);
    }
};

export const handleOutboundClickToCallStatusUpdated = (payload) => {
    applyLifecycleUpdate(payload?.call);
};

export const bindOutboundClickToCallStatusChannel = (channel) => {
    if (!channel?.listen) {
        return;
    }

    if (boundChannel === channel) {
        return;
    }

    if (boundChannel && channelHandler && typeof boundChannel.stopListening === 'function') {
        boundChannel.stopListening('.OutboundClickToCallStatusUpdated', channelHandler);
    }

    channelHandler = handleOutboundClickToCallStatusUpdated;
    channel.listen('.OutboundClickToCallStatusUpdated', channelHandler);
    boundChannel = channel;
};

export const trackOutboundClickToCall = ({ eventId, button }) => {
    if (!eventId || !(button instanceof HTMLButtonElement)) {
        return;
    }

    stopTracking();

    if (!button.dataset.bonvoiceDefaultLabel) {
        const labelNode = button.querySelector('[data-bonvoice-call-status-label]');
        button.dataset.bonvoiceDefaultLabel = labelNode?.textContent?.trim() || 'Call';
    }

    activeTracker = {
        eventId,
        button,
        resetTimeoutId: null,
    };

    applyLifecycleToButton(button, 'calling');
};

export const resetOutboundClickToCallTracking = () => {
    stopTracking();
    boundChannel = null;
    channelHandler = null;
};
