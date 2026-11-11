import {
    isAllowedDeskSender,
    isAllowedDeskUrl,
    isAllowedPortalMessage,
    isAllowedProviderSenderUrl,
} from './lib/desk-url.js';

const START_TYPE = 'RDESK_OEM_WARRANTY_START';
const DRAFT_TYPE = 'RDESK_OEM_WARRANTY_DRAFT';

chrome.runtime.onMessageExternal.addListener((message, sender, sendResponse) => {
    if (!isAllowedDeskSender(sender)) {
        sendResponse({ ok: false, reason: 'sender_not_allowed' });
        return;
    }

    if (!isAllowedPortalMessage(message)) {
        sendResponse({ ok: false, reason: 'invalid_message' });
        return;
    }

    chrome.storage.session.set({ activeLookup: message }).then(() => {
        chrome.tabs.create({ url: message.portal_url, active: true });
        sendResponse({ ok: true });
    });

    return true;
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (message?.type !== DRAFT_TYPE) {
        return;
    }

    if (!sender.tab?.url || !isAllowedProviderSenderUrl(sender.tab.url)) {
        sendResponse({ ok: false, reason: 'provider_sender_not_allowed' });
        return;
    }

    chrome.storage.session.get('activeLookup').then(({ activeLookup }) => {
        if (!activeLookup || activeLookup.handoff_token !== message.handoff_token) {
            sendResponse({ ok: false, reason: 'session_mismatch' });
            return;
        }

        if (activeLookup.extension_dom_enabled !== true) {
            sendResponse({ ok: false, reason: 'dom_capture_disabled' });
            return;
        }

        chrome.tabs.query({}).then((tabs) => {
            const deskTab = tabs.find((tab) => typeof tab.url === 'string' && isAllowedDeskUrl(tab.url));
            if (!deskTab?.id) {
                sendResponse({ ok: false, reason: 'desk_tab_missing' });
                return;
            }

            chrome.tabs.sendMessage(deskTab.id, { ...message, type: DRAFT_TYPE });
            sendResponse({ ok: true });
        });
    });

    return true;
});
