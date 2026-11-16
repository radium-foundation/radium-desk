import { MSG } from '../lib/constants.js';
import { claimHandoffWithRetry } from '../lib/mantra-handoff-claim.js';
import { showMantraHandoffStatus } from '../lib/mantra-page-ui.js';
import {
    fillMantraSerialOnce,
    MANTRA_SERIAL_FIELD_MAX_WAIT_MS,
    waitForMantraSerialField,
} from '../lib/mantra-serial-field-wait.js';
import {
    inferMantraOutcome,
    isMantraResultVisible,
    readMantraResultTable,
} from '../lib/mantra-result-parse.js';

const OEM = 'mantra';

const sendClaim = (payload) => new Promise((resolve) => {
    chrome.runtime.sendMessage(payload, (response) => {
        if (chrome.runtime.lastError) {
            resolve({ ok: false, error: 'no-handoff' });
            return;
        }
        resolve(response ?? { ok: false, error: 'handoff-failed' });
    });
});

const runMantraHandoff = async () => {
    const claim = await claimHandoffWithRetry(sendClaim, { oem: OEM });

    if (!claim?.ok || typeof claim.serial !== 'string') {
        showMantraHandoffStatus(document, 'failure');
        return;
    }

    try {
        await waitForMantraSerialField(document, '#txtSerialNo', MANTRA_SERIAL_FIELD_MAX_WAIT_MS);
    } catch {
        showMantraHandoffStatus(document, 'timeout');
        return;
    }

    const fillResult = fillMantraSerialOnce(document, claim.serial);
    if (!fillResult.ok) {
        showMantraHandoffStatus(document, 'failure');
        return;
    }

    showMantraHandoffStatus(document, 'success');

    if (claim.mantra_capture_enabled !== true) {
        return;
    }

    let draftSent = false;

    const tryEmitDraft = () => {
        if (draftSent) {
            return;
        }

        const rows = readMantraResultTable(document);
        if (!rows.length || !isMantraResultVisible(document)) {
            return;
        }

        draftSent = true;
        observer.disconnect();

        chrome.runtime.sendMessage({
            type: MSG.HANDOFF_MANTRA_DRAFT,
            handoff_token: claim.handoff_token,
            incident_id: claim.incident_id,
            session_public_id: claim.session_public_id,
            outcome: inferMantraOutcome(rows),
            rows,
        });
    };

    const observer = new MutationObserver(() => {
        tryEmitDraft();
    });

    observer.observe(document.body, { childList: true, subtree: true });
    tryEmitDraft();
};

void runMantraHandoff();