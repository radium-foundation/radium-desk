import { HandoffStore } from './lib/handoff-store.js';
import { MSG, OEM_PAGE_URLS } from './lib/constants.js';
import { isAllowedDeskSenderUrl, isValidExtensionSender } from './lib/origin-guard.js';
import { isExpectedOemDestinationUrl, isOemVendor, isValidHandoffSerial } from './lib/handoff-payload.js';

const store = new HandoffStore();

/** @type {Map<number, { handoff_token?: string, incident_id?: number, session_public_id?: string, mantra_capture_enabled: boolean, oem: string }>} */
const sessionMetaByTabId = new Map();

const DRAFT_TO_DESK = 'OEM_HANDOFF_DRAFT_TO_DESK';

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (!isValidExtensionSender(sender)) {
        sendResponse({ ok: false, error: 'invalid-sender' });
        return false;
    }

    if (message?.type === MSG.HANDOFF_REQUEST) {
        const pageUrl = sender.tab?.url;
        if (!isAllowedDeskSenderUrl(pageUrl)) {
            sendResponse({ ok: false, error: 'origin-denied' });
            return false;
        }

        const { oem, serial } = message;
        if (!isOemVendor(oem) || typeof serial !== 'string' || !isValidHandoffSerial(serial)) {
            sendResponse({ ok: false, error: 'invalid-payload' });
            return false;
        }

        const url = OEM_PAGE_URLS[oem];
        if (!isExpectedOemDestinationUrl(url, oem)) {
            sendResponse({ ok: false, error: 'destination-mismatch' });
            return false;
        }

        const { handoffId } = store.create({ oem, serial: serial.trim().toUpperCase() });

        chrome.tabs.create({ url, active: true }, (tab) => {
            if (!tab?.id) {
                store.discard(handoffId);
                sendResponse({ ok: false, error: 'tab-create-failed' });

                return;
            }

            store.bindTab(handoffId, tab.id);

            sessionMetaByTabId.set(tab.id, {
                handoff_token: typeof message.handoff_token === 'string' ? message.handoff_token : undefined,
                incident_id: typeof message.incident_id === 'number' ? message.incident_id : undefined,
                session_public_id: typeof message.session_public_id === 'string' ? message.session_public_id : undefined,
                mantra_capture_enabled: message.mantra_capture_enabled === true,
                oem,
            });

            sendResponse({ ok: true, handoffId });
        });

        return true;
    }

    if (message?.type === MSG.HANDOFF_CLAIM) {
        const tabId = sender.tab?.id;
        const pageUrl = sender.tab?.url;
        const { oem } = message;

        if (tabId == null || !isOemVendor(oem)) {
            sendResponse({ ok: false, error: 'invalid-claim' });
            return false;
        }

        if (!isExpectedOemDestinationUrl(pageUrl, oem)) {
            sendResponse({ ok: false, error: 'claim-origin-mismatch' });
            return false;
        }

        const claimed = store.consume({ tabId, oem });
        if (!claimed) {
            sendResponse({ ok: false, error: 'no-handoff' });
            return false;
        }

        const meta = sessionMetaByTabId.get(tabId) ?? { mantra_capture_enabled: false, oem };

        sendResponse({
            ok: true,
            serial: claimed.serial,
            handoff_token: meta.handoff_token,
            incident_id: meta.incident_id,
            session_public_id: meta.session_public_id,
            mantra_capture_enabled: meta.mantra_capture_enabled,
        });

        return false;
    }

    if (message?.type === MSG.HANDOFF_MANTRA_DRAFT) {
        const tabId = sender.tab?.id;
        const pageUrl = sender.tab?.url;

        if (tabId == null || !isExpectedOemDestinationUrl(pageUrl, 'mantra')) {
            sendResponse({ ok: false, error: 'invalid-mantra-draft-sender' });
            return false;
        }

        const meta = sessionMetaByTabId.get(tabId);
        if (!meta || meta.mantra_capture_enabled !== true) {
            sendResponse({ ok: false, error: 'capture-disabled' });
            return false;
        }

        if (typeof message.handoff_token !== 'string' || message.handoff_token !== meta.handoff_token) {
            sendResponse({ ok: false, error: 'token-mismatch' });
            return false;
        }

        chrome.tabs.query({}, (tabs) => {
            const deskTab = tabs.find(
                (tab) => typeof tab.url === 'string' && isAllowedDeskSenderUrl(tab.url),
            );

            if (!deskTab?.id) {
                sendResponse({ ok: false, error: 'desk-tab-missing' });
                return;
            }

            chrome.tabs.sendMessage(deskTab.id, {
                type: DRAFT_TO_DESK,
                handoff_token: meta.handoff_token,
                incident_id: meta.incident_id,
                session_public_id: meta.session_public_id,
                outcome: message.outcome,
                rows: message.rows ?? [],
            });

            sessionMetaByTabId.delete(tabId);
            sendResponse({ ok: true });
        });

        return true;
    }

    sendResponse({ ok: false, error: 'unknown-type' });
    return false;
});
