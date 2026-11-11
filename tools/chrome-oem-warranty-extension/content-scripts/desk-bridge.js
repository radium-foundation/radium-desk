const DRAFT_TYPE = 'RDESK_OEM_WARRANTY_DRAFT';

chrome.runtime.onMessage.addListener((message) => {
    if (message?.type !== DRAFT_TYPE) {
        return;
    }

    window.postMessage(message, window.location.origin);
});
