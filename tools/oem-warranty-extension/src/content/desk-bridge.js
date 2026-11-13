(function () {
    const DRAFT_TO_DESK = 'OEM_HANDOFF_DRAFT_TO_DESK';

    chrome.runtime.onMessage.addListener((message) => {
        if (message?.type !== DRAFT_TO_DESK) {
            return;
        }

        window.postMessage(
            {
                type: 'RADESK_OEM_HANDOFF_DRAFT',
                handoff_token: message.handoff_token,
                incident_id: message.incident_id,
                session_public_id: message.session_public_id,
                outcome: message.outcome,
                rows: message.rows,
            },
            window.location.origin,
        );
    });
})();
