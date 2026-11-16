import { OEM_PAGE_URLS } from './constants.js';
import { isAllowedDeskSenderUrl } from './origin-guard.js';
import { isExpectedOemDestinationUrl, isOemVendor, isValidHandoffSerial } from './handoff-payload.js';

/**
 * Validate and start an OEM handoff. Responds synchronously so Desk is not left waiting on
 * chrome.tabs.create (MV3 service workers can suspend before the tabs callback runs).
 *
 * @param {chrome.runtime.MessageSender} sender
 * @param {{ oem?: unknown, serial?: unknown, handoff_token?: unknown, incident_id?: unknown, session_public_id?: unknown, mantra_capture_enabled?: unknown }} message
 * @param {{
 *   store: { create: (p: { oem: string, serial: string }) => { handoffId: string }, bindTab: (id: string, tabId: number) => boolean, discard: (id: string) => void },
 *   setSessionMeta: (tabId: number, meta: Record<string, unknown>) => void,
 *   openOemTab: (url: string) => Promise<{ id?: number } | undefined>,
 * }} deps
 */
export function handleHandoffRequest(sender, message, deps) {
    const pageUrl = sender.tab?.url;
    if (!isAllowedDeskSenderUrl(pageUrl)) {
        return { ok: false, error: 'origin-denied' };
    }

    const { oem, serial } = message;
    if (!isOemVendor(oem) || typeof serial !== 'string' || !isValidHandoffSerial(serial)) {
        return { ok: false, error: 'invalid-payload' };
    }

    const url = OEM_PAGE_URLS[oem];
    if (!isExpectedOemDestinationUrl(url, oem)) {
        return { ok: false, error: 'destination-mismatch' };
    }

    const { handoffId } = deps.store.create({ oem, serial: serial.trim().toUpperCase() });

    void deps.openOemTab(url).then((tab) => {
        if (!tab?.id) {
            deps.store.discard(handoffId);
            return;
        }

        deps.store.bindTab(handoffId, tab.id);
        deps.setSessionMeta(tab.id, {
            handoff_token: typeof message.handoff_token === 'string' ? message.handoff_token : undefined,
            incident_id: typeof message.incident_id === 'number' ? message.incident_id : undefined,
            session_public_id: typeof message.session_public_id === 'string' ? message.session_public_id : undefined,
            mantra_capture_enabled: message.mantra_capture_enabled === true,
            oem,
        });
    });

    return { ok: true, handoffId };
}