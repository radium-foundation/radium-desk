import { HandoffStore } from './lib/handoff-store.js';
import { MSG, OEM_PAGE_URLS } from './lib/constants.js';
import { isAllowedDeskSenderUrl, isValidExtensionSender } from './lib/origin-guard.js';
import { isExpectedOemDestinationUrl, isOemVendor, isValidHandoffSerial } from './lib/handoff-payload.js';

const store = new HandoffStore();

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

        sendResponse({ ok: true, serial: claimed.serial });
        return false;
    }

    sendResponse({ ok: false, error: 'unknown-type' });
    return false;
});
