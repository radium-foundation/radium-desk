import { ALLOWED_DESK_ORIGIN_PREFIXES } from './constants.js';

/**
 * @param {string | undefined} url
 * @returns {boolean}
 */
export function isAllowedDeskSenderUrl(url) {
    if (!url || typeof url !== 'string') {
        return false;
    }
    return ALLOWED_DESK_ORIGIN_PREFIXES.some((prefix) => url.startsWith(prefix));
}

/**
 * @param {chrome.runtime.MessageSender} sender
 * @returns {boolean}
 */
export function isValidExtensionSender(sender) {
    return Boolean(sender?.id);
}
