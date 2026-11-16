/**
 * window.postMessage bridge between extension content scripts and Desk page bundle.
 * CustomEvents on document are not shared across Chrome's isolated worlds.
 */
export const DESK_WINDOW_MSG = {
    HANDOFF_REQUEST: 'RADESK_OEM_HANDOFF_REQUEST',
    SESSION_PENDING: 'RADESK_OEM_HANDOFF_SESSION_PENDING',
    SESSION_READY: 'RADESK_OEM_HANDOFF_SESSION_READY',
    HANDOFF_STATUS: 'RADESK_OEM_HANDOFF_STATUS',
};

/**
 * @param {Record<string, unknown>} payload
 * @param {string} origin
 */
export function postToDeskPage(payload, origin) {
    if (typeof window === 'undefined') {
        return;
    }
    window.postMessage(payload, origin);
}