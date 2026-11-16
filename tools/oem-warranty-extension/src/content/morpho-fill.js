import { MSG } from '../lib/constants.js';
import { claimHandoffWithRetry } from '../lib/mantra-handoff-claim.js';
import { populateMorphoSerialFieldDomOnly } from '../lib/morpho-populate.js';
import {
    inferMorphoOutcome,
    isMorphoResultVisible,
    morphoFieldsForCapture,
    readMorphoResultFields,
} from '../lib/morpho-result-parse.js';
import { observeOemResult } from '../lib/oem-result-observe.js';

const OEM = 'morpho';

const sendClaim = (payload) => new Promise((resolve) => {
    chrome.runtime.sendMessage(payload, (response) => {
        if (chrome.runtime.lastError) {
            resolve({ ok: false, error: 'no-handoff' });
            return;
        }
        resolve(response ?? { ok: false, error: 'handoff-failed' });
    });
});

const showBanner = (message, tone = 'info') => {
    const bannerId = 'radium-oem-handoff-banner';
    if (document.getElementById(bannerId)) {
        return;
    }
    const el = document.createElement('p');
    el.id = bannerId;
    el.setAttribute('role', 'status');
    el.textContent = message;
    const bg = tone === 'warn' ? '#fff3cd' : '#e7f3ff';
    const border = tone === 'warn' ? '#ffeeba' : '#b8daff';
    el.style.cssText = `margin:8px 0;padding:8px;background:${bg};border:1px solid ${border};font-size:13px;`;
    const anchor = document.getElementById('device-serial-number1');
    anchor?.parentElement?.prepend(el);
};

const emitMorphoResult = (claim, fields, outcome) => {
    chrome.runtime.sendMessage({
        type: MSG.HANDOFF_OEM_RESULT,
        oem: OEM,
        handoff_token: claim.handoff_token,
        incident_id: claim.incident_id,
        session_public_id: claim.session_public_id,
        outcome,
        fields,
    });
};

const runMorphoHandoff = async () => {
    const claim = await claimHandoffWithRetry(sendClaim, { oem: OEM });

    if (!claim?.ok || typeof claim.serial !== 'string') {
        showBanner('Serial handoff was not available. Enter the serial manually.', 'warn');
        return;
    }

    const fill = populateMorphoSerialFieldDomOnly(document, claim.serial);
    if (!fill.ok) {
        showBanner('Serial could not be filled automatically. Enter it manually.', 'warn');
        return;
    }

    showBanner('Serial prefilled (visual only). Complete OEM validation manually, then review results in Desk.');

    const captureEnabled = claim.result_capture_enabled === true || claim.morpho_capture_enabled === true;
    if (!captureEnabled) {
        return;
    }

    observeOemResult(document, {
        isReady: isMorphoResultVisible,
        buildPayload: (doc) => {
            const parsed = readMorphoResultFields(doc);
            return {
                outcome: inferMorphoOutcome(parsed),
                fields: morphoFieldsForCapture(parsed),
            };
        },
        onEmit: ({ outcome, fields }) => {
            showBanner('OEM result captured for Desk review. Return to Desk to confirm or reject.');
            emitMorphoResult(claim, fields, outcome);
        },
    });
};

void runMorphoHandoff();