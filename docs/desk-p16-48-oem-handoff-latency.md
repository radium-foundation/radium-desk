# P-16-48 — OEM handoff timeout and latency

## Symptoms

Mantra icon click with session capture shows:

> OEM handoff timed out. Try again or enter the serial manually on the OEM site.

## Verified root causes (production diagnosis)

### 1. Chrome isolated worlds (addressed in v4.1.71 + extension v0.3.8)

Session-mode handoff needs Desk **page bundle** code for `POST .../oem-handoff/sessions`. Content-script `CustomEvent`s do not reach the page world ([P-16-43](./desk-p16-43-oem-handoff-timeout.md)). Fix: `window.postMessage` bridge. Without **both** deployed Desk **4.1.71+** and extension **0.3.8+**, the page never creates a session and the content script hits the 12s watchdog.

**Production signal (2026-11-16):** `0` `dashboard_oem_handoff_sessions` rows created after v4.1.71 deploy → attempts still failing before session persistence (stale dashboard bundle, missing extension upgrade, or both — not measured in-browser here).

### 2. Single 12s deadline for POST + extension ack (fixed in app + extension **0.3.9**)

In extension **0.3.8** `desk-handoff.js`, one timer started at **click** covered:

1. Page `POST` session API  
2. `SESSION_READY` postMessage  
3. `chrome.runtime.sendMessage` + tab open  

A slow-but-successful POST at **~11s** plus extension ack **>1s** falsely timed out at **12s** even though the server eventually returned a session.

**Fix:**

| Phase | Watchdog | Trigger to re-arm |
|-------|-----------|-------------------|
| Wait for session | 12s | click → `SESSION_PENDING` (page started POST) |
| Wait for extension ack | fresh 12s | `SESSION_READY` |

Desk posts `RADESK_OEM_HANDOFF_SESSION_PENDING` immediately before the session `POST`.

## Operator actions (no code)

1. Hard-refresh dashboard (or clear cache) so `dashboard-*.js` includes `RADESK_OEM_HANDOFF_*` strings.  
2. Install extension **v0.3.9** (or newer). **v0.3.7 and below** cannot run session capture with current Desk.  
3. Confirm Network: `POST .../oem-handoff/sessions` **201** within the first phase, not only after timeout toast.

## Not changed

- Mantra CAPTCHA / Check remain manual.  
- Cashfree / payment authority unchanged.  
- No production deploy from this prompt.