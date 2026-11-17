import { MSG } from '../lib/constants.js';
import { claimHandoffWithRetry } from '../lib/mantra-handoff-claim.js';
import {
    focusMantraCaptchaField,
    highlightMantraCaptchaField,
    MANTRA_CAPTCHA_FIELD_MAX_WAIT_MS,
    waitForMantraCaptchaField,
} from '../lib/mantra-operator-assist.js';
import { showMantraHandoffStatus } from '../lib/mantra-page-ui.js';
import {
    fillMantraSerialOnce,
    MANTRA_SERIAL_FIELD_MAX_WAIT_MS,
    waitForMantraSerialField,
} from '../lib/mantra-serial-field-wait.js';
import { observeOemResult } from '../lib/oem-result-observe.js';
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

    try {
        await waitForMantraCaptchaField(document, MANTRA_CAPTCHA_FIELD_MAX_WAIT_MS);
    } catch {
        // CAPTCHA field may appear later; focus/highlight are best-effort.
    }

    focusMantraCaptchaField(document);
    highlightMantraCaptchaField(document);

    const captureEnabled = claim.result_capture_enabled === true || claim.mantra_capture_enabled === true;
    if (!captureEnabled) {
        return;
    }

    observeOemResult(document, {
        isReady: (doc) => {
            const rows = readMantraResultTable(doc);
            return rows.length > 0 && isMantraResultVisible(doc);
        },
        buildPayload: (doc) => {
            const rows = readMantraResultTable(doc);
            if (!rows.length) {
                return null;
            }

            return {
                outcome: inferMantraOutcome(rows),
                rows,
            };
        },
        onEmit: ({ outcome, rows }) => {
            chrome.runtime.sendMessage({
                type: MSG.HANDOFF_OEM_RESULT,
                oem: OEM,
                handoff_token: claim.handoff_token,
                incident_id: claim.incident_id,
                session_public_id: claim.session_public_id,
                outcome,
                rows,
            });
        },
    });
};

void runMantraHandoff();