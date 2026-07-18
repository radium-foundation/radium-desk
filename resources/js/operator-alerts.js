/**
 * Phase 0 Operator Alert System client.
 *
 * Listens for OperatorAlertRaised (via Reverb) and shows browser notifications.
 * Does not integrate with the notification bell badge or dropdown.
 */

const shownDeduplicationKeys = new Set();

const playOperatorAlertSound = (payload) => {
    if (!payload?.play_sound) {
        return;
    }

    // Phase 0 stub — sound playback lands in a later phase.
};

const showOperatorBrowserNotification = (payload) => {
    if (!payload?.desktop_popup) {
        return;
    }

    if (!('Notification' in window) || Notification.permission !== 'granted') {
        return;
    }

    const deduplicationKey = payload.deduplication_key ?? payload.action_url ?? payload.title;

    if (deduplicationKey && shownDeduplicationKeys.has(deduplicationKey)) {
        return;
    }

    if (deduplicationKey) {
        shownDeduplicationKeys.add(deduplicationKey);
    }

    try {
        const notification = new Notification(payload.title ?? 'Alert', {
            body: payload.message ?? '',
            tag: deduplicationKey ?? undefined,
            icon: undefined,
        });

        notification.onclick = () => {
            window.focus();

            if (payload.action_url) {
                window.location.href = payload.action_url;
            }

            notification.close();
        };
    } catch (error) {
        // Gracefully ignore blocked or unsupported browser notifications.
    }
};

export const handleOperatorAlertRaised = (payload) => {
    if (!payload) {
        return;
    }

    showOperatorBrowserNotification(payload);
    playOperatorAlertSound(payload);
};

export const bindOperatorAlertsChannel = (channel) => {
    if (!channel?.listen) {
        return;
    }

    channel.listen('.OperatorAlertRaised', (payload) => {
        handleOperatorAlertRaised(payload);
    });
};

export const resetOperatorAlertDeduplication = () => {
    shownDeduplicationKeys.clear();
};

export {
    playOperatorAlertSound,
    showOperatorBrowserNotification,
};
