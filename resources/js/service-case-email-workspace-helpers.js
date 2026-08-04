/**
 * Email workspace helpers — re-export shared channel UX + email-specific cursors.
 */
export {
    canStartSend,
    isNearBottom,
    preferDefaultSubject,
    textToHtml,
    notifyChannelSuccess,
    notifyChannelFailure,
    highlightChannelTarget,
} from './c360-channel-ux';

export const buildThreadQuery = (baseUrl, params = {}) => {
    const url = new URL(baseUrl, window.location.origin);

    Object.entries(params).forEach(([key, value]) => {
        if (value === null || value === undefined || value === '') {
            return;
        }

        url.searchParams.set(key, String(value));
    });

    return `${url.pathname}${url.search}`;
};

export const oldestCursorFromMessages = (messages) => {
    if (! Array.isArray(messages) || messages.length === 0) {
        return null;
    }

    const first = messages[0];

    return {
        before_at: first.occurred_at,
        before_id: first.id,
        before_direction: first.direction,
    };
};

export const newestCursorFromMessages = (messages) => {
    if (! Array.isArray(messages) || messages.length === 0) {
        return null;
    }

    const last = messages[messages.length - 1];

    return {
        since_at: last.occurred_at,
        since_id: last.id,
        since_direction: last.direction,
    };
};
