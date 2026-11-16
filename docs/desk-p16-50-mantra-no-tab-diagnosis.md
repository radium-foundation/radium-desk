# P-16-50 — Mantra OEM page does not open (diagnosis)

## Production evidence (2026-11-16 after v4.1.72 deploy)

| Signal | Value |
|--------|--------|
| `dashboard_oem_handoff_sessions` row count (all time on prod) | **0** |
| POST reaching persistence | **No** observed |
| Laravel oem-handoff log lines | **None** surfaced in today’s log sweep |

## Root cause assessment

| Item | Status |
|------|--------|
| Phase-1 failure (no `SESSION_READY` / no `POST` session) | **VERIFIED** — zero session rows |
| Extension **v0.3.9** on operator workstation | **UNKNOWN** (not inspectable from Cursor) |
| Stale dashboard bundle without `RADESK_OEM_HANDOFF_REQUEST` | **INFERRED** — same symptom class as P-16-43/P-16-48 |
| Extension &lt; **0.3.8** (no postMessage session bridge) | **INFERRED** — consistent with phase-1 failure |
| Server-side POST rejection | **Unlikely** — would usually surface non-timeout errors when page receives the request |

## Which timer fires the generic timeout toast?

Content-script `desk-handoff.js` arms **12s** watchdogs:

1. **`timeout-awaiting-session`** (after P-16-50 code): click → before `RADESK_OEM_HANDOFF_SESSION_READY` / extension tab open.
2. **`timeout-awaiting-extension`**: after `SESSION_READY`, while waiting for `chrome.runtime.sendMessage` ack + tab.

With **0** DB sessions, production failures are **phase 1** (Desk page never completes session creation path).

## Isolated-world status toasts (code gap fixed in P-16-50)

Extension content scripts could not show Desk toasts: `radium:oem-handoff-status` `CustomEvent`s on `document` do not reach the page bundle. Content script already posted `RADESK_OEM_HANDOFF_STATUS`, but the page bundle **did not listen**. P-16-50 adds the page listener and phase-specific timeout codes.

## Operator checklist (before next deploy)

1. Install **v0.3.9+** internal extension ZIP (SHA in `SHA256SUMS.txt`).
2. Hard-refresh Desk dashboard.
3. DevTools → Network: confirm `POST .../oem-handoff/sessions` returns **200** JSON (not missing).
4. Mantra tab should open only **after** session POST + extension ack — CAPTCHA/Check remain manual.