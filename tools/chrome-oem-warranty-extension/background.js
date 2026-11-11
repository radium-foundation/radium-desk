const START_TYPE = 'RDESK_OEM_WARRANTY_START';
const DRAFT_TYPE = 'RDESK_OEM_WARRANTY_DRAFT';

chrome.runtime.onMessageExternal.addListener((message, sender, sendResponse) => {
    if (message?.type !== START_TYPE) {
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

    chrome.storage.session.get('activeLookup').then(({ activeLookup }) => {
        if (!activeLookup || activeLookup.handoff_token !== message.handoff_token) {
            sendResponse({ ok: false, reason: 'session_mismatch' });
            return;
        }

        chrome.tabs.query({}).then((tabs) => {
            const deskTab = tabs.find((tab) => typeof tab.url === 'string' && tab.url.includes('/orders/'));
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
