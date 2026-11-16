# P-16-43 — Production Mantra OEM handoff timeout

## Symptom

With `DASHBOARD_OEM_HANDOFF_ENABLED` and `DASHBOARD_OEM_HANDOFF_MANTRA_CAPTURE` enabled, clicking the Mantra icon shows:

> OEM handoff timed out. Try again or enter the serial manually on the OEM site.

Legacy prefill-only mode (flags off) still worked.

## Root cause

**Not a repeat of P-16-23.** P-16-23 fixed MV3 `sendResponse` deferral for **legacy** `chrome.runtime.sendMessage` from the content script.

With sessions enabled, the flow splits across **two JavaScript worlds**:

1. Extension **content script** (`desk-handoff.js`) — intercepts the click and starts a 12s timer.
2. Desk **page bundle** (`dashboard-oem-warranty-handoff.js`) — must POST the session API and call `chrome.runtime.sendMessage`.

Chrome extension content scripts and the page bundle run in **isolated worlds**. `document.dispatchEvent(CustomEvent)` from the content script **does not reach** page-bundle listeners, and `chrome.runtime` is **not available** in the page bundle.

Result: the page never created a session or messaged the background worker; the content script fired **timeout** at 12s. Production showed **zero** `dashboard_oem_handoff_sessions` rows during failed attempts.

## Fix

Use `window.postMessage` on the shared `window` (same pattern as `desk-bridge.js` → `RADESK_OEM_HANDOFF_DRAFT`):

| Step | Actor | Message |
|------|--------|---------|
| 1 | Content script | `RADESK_OEM_HANDOFF_REQUEST` |
| 2 | Page bundle | POST session → `RADESK_OEM_HANDOFF_SESSION_READY` |
| 3 | Content script | `chrome.runtime.sendMessage` (P-16-23 sync ack preserved) |
| 4 | Either side errors | `RADESK_OEM_HANDOFF_STATUS` |

**Extension v0.3.8** + deployed Desk JS containing the page-side bridge.

## Operator verification (after deploy + ext install)

1. Install **v0.3.8** (`radium-desk-v0.3.8.zip`, SHA in `SHA256SUMS.txt`).
2. Hard-refresh the hardware dashboard (clear cached `dashboard-*.js` if needed).
3. Click Mantra on an authorized row → Network: **POST** `.../oem-handoff/sessions` **201** within ~1s (not 12s timeout).
4. Mantra tab opens with serial prefilled; complete CAPTCHA + Check manually.
5. Desk shows draft review panel (do not confirm unless instructed).

DevTools (content script console): no `timeout` on `radium:oem-handoff-status` within 12s.

## Production configuration

No `.env` flag changes required beyond P-16-42. Deploy Desk assets when the JS change ships; redistribute extension **0.3.8**.