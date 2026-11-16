import { HandoffStore } from './lib/handoff-store.js';
import { MSG } from './lib/constants.js';
import { handleHandoffRequest } from './lib/handoff-request-handler.js';
import { isAllowedDeskSenderUrl, isValidExtensionSender } from './lib/origin-guard.js';
import { isExpectedOemDestinationUrl, isOemVendor } from './lib/handoff-payload.js';

const store = new HandoffStore();

/** @type {Map<number, { handoff_token?: string, incident_id?: number, session_public_id?: string, result_capture_enabled: boolean, mantra_capture_enabled: boolean, morpho_capture_enabled: boolean, oem: string }>} */
const sessionMetaByTabId = new Map();

const DRAFT_TO_DESK = 'OEM_HANDOFF_DRAFT_TO_DESK';

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (!isValidExtensionSender(sender)) {
        sendResponse({ ok: false, error: 'invalid-sender' });
        return false;
    }

    if (message?.type === MSG.HANDOFF_REQUEST) {
        const result = handleHandoffRequest(sender, message, {
            store,
            setSessionMeta: (tabId, meta) => {
                sessionMetaByTabId.set(tabId, meta);
            },
            openOemTab: (url) => chrome.tabs.create({ url, active: true }),
        });

        sendResponse(result);
        return false;
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

        const meta = sessionMetaByTabId.get(tabId) ?? {
            result_capture_enabled: false,
            mantra_capture_enabled: false,
            morpho_capture_enabled: false,
            oem,
        };

        sendResponse({
            ok: true,
            serial: claimed.serial,
            handoff_token: meta.handoff_token,
            incident_id: meta.incident_id,
            session_public_id: meta.session_public_id,
            result_capture_enabled: meta.result_capture_enabled === true,
            mantra_capture_enabled: meta.mantra_capture_enabled === true,
            morpho_capture_enabled: meta.morpho_capture_enabled === true,
        });

        return false;
    }

    if (message?.type === MSG.HANDOFF_OEM_RESULT || message?.type === MSG.HANDOFF_MANTRA_DRAFT) {
        const tabId = sender.tab?.id;
        const pageUrl = sender.tab?.url;
        const oem = message?.oem ?? 'mantra';

        if (tabId == null || !isOemVendor(oem) || !isExpectedOemDestinationUrl(pageUrl, oem)) {
            sendResponse({ ok: false, error: 'invalid-oem-result-sender' });
            return false;
        }

        const meta = sessionMetaByTabId.get(tabId);
        if (!meta || meta.result_capture_enabled !== true) {
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
                oem,
                outcome: message.outcome,
                rows: message.rows ?? [],
                fields: message.fields ?? null,
                whitelist_qualification: message.whitelist_qualification ?? null,
            });

            sessionMetaByTabId.delete(tabId);
            sendResponse({ ok: true });
        });

        return true;
    }

    sendResponse({ ok: false, error: 'unknown-type' });
    return false;
});
