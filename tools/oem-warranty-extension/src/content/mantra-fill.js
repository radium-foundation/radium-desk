import { populateMantraSerialField } from '../lib/mantra-populate.js';
import {
    inferMantraOutcome,
    isMantraResultVisible,
    readMantraResultTable,
} from '../lib/mantra-result-parse.js';

const OEM = 'mantra';

function showFailure() {
    const bannerId = 'radium-oem-handoff-banner';
    if (document.getElementById(bannerId)) {
        return;
    }
    const el = document.createElement('p');
    el.id = bannerId;
    el.setAttribute('role', 'status');
    el.textContent = 'Serial could not be filled automatically. Enter it manually on this page.';
    el.style.cssText = 'margin:8px 0;padding:8px;background:#fff3cd;border:1px solid #ffeeba;font-size:13px;';
    document.getElementById('txtSerialNo')?.parentElement?.prepend(el);
}

chrome.runtime.sendMessage({ type: 'OEM_HANDOFF_CLAIM', oem: OEM }, (response) => {
    if (chrome.runtime.lastError || !response?.ok) {
        showFailure();
        return;
    }

    const populateResult = populateMantraSerialField(document, response.serial);
    if (!populateResult.ok) {
        showFailure();
        return;
    }

    if (response.mantra_capture_enabled !== true) {
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
            type: 'OEM_HANDOFF_MANTRA_DRAFT',
            handoff_token: response.handoff_token,
            incident_id: response.incident_id,
            session_public_id: response.session_public_id,
            outcome: inferMantraOutcome(rows),
            rows,
        });
    };

    const observer = new MutationObserver(() => {
        tryEmitDraft();
    });

    observer.observe(document.body, { childList: true, subtree: true });
    tryEmitDraft();
});
