# P-16-41 — Mantra warranty result reception (configuration runbook)

**Production baseline:** Radium Desk **v4.1.70** (`2b6746f62`, application **0fe4a964**).  
**Extension authoritative build:** `tools/oem-warranty-extension` **v0.3.8** (`distribution/radium-desk-v0.3.8.zip`; session bridge P-16-43).

## Diagnosis summary (P-16-41)

When OEM handoff sessions are **disabled** on Desk, clicking the Mantra icon uses **legacy one-way handoff**: the extension opens Mantra, prefills the serial, and shows the green banner — but **does not** start a Desk session, **does not** observe the result table, and **does not** post a draft to Desk.

Production on **2026-11-16** had all OEM flags **false** (verified via `config('dashboard_oem_handoff')`). No `oem-handoff` API traffic is expected in that mode.

## Two-way flow (when correctly enabled)

1. Operator clicks Mantra icon on a dashboard row (`data-incident-id`, serial from row).
2. Desk JS (`dashboard-oem-warranty-handoff.js`) POSTs **`/dashboard/service-cases/{id}/oem-handoff/sessions`** → session + `handoff_token`.
3. Desk JS sends **`OEM_HANDOFF_REQUEST`** to the extension with token, session id, and capture flags.
4. Extension opens Mantra tab, `mantra-fill.bundle.js` claims handoff, prefills serial (operator completes CAPTCHA and clicks **Check** manually).
5. When `#TblRDWarrantyCheck` shows rows, extension observes DOM and sends **`HANDOFF_OEM_RESULT`** via background → `desk-bridge.js` → `RADESK_OEM_HANDOFF_DRAFT` → Desk POST **`.../draft`**.
6. Administrator sees inline **Confirm / Reject** panel; confirm stores evidence only (no automatic service reference assignment unless separate flags are enabled later).

Correlation uses **handoff session + token**, not serial alone.

## Minimum production configuration (Owner approval required)

| Flag | Required for Mantra results? | Effect if `true` |
|------|------------------------------|------------------|
| `DASHBOARD_OEM_HANDOFF_ENABLED` | **Yes** | Enables session APIs and session-based handoff from Desk (sets `data-dashboard-oem-handoff-enabled="1"`). |
| `DASHBOARD_OEM_HANDOFF_MANTRA_CAPTURE` | **Yes** | Enables Mantra DOM result capture + draft API; extension sets `mantra_capture_enabled` / `result_capture_enabled`. |
| `DASHBOARD_OEM_HANDOFF_MORPHO_CAPTURE` | **No** (keep **false** per P-16-41) | Morpho two-way capture. |
| `DASHBOARD_OEM_HANDOFF_EXPIRY_PERSIST` | **No** | After **confirm**, persist RD expiry to `service_reference_oem_subscription_expiries`. |
| `DASHBOARD_OEM_HANDOFF_SERVICE_REFERENCE_SUGGEST` | **No** | After **confirm**, advisory `AW-DD-MM-YY-NN` suggestion (still requires manual assign). |
| `DASHBOARD_OEM_HANDOFF_PRUNE_ENABLED` | **No** | Scheduled session pruning only. |

After changing flags: `php artisan config:cache` on KVM (separate Owner-gated step; **not** executed in P-16-41).

## Extension requirement

Install **v0.3.7** (or newer matching Desk **v4.1.70**+) on administrator browsers. Legacy handoff works on older builds; **two-way capture** requires bundled `mantra-fill.bundle.js` with `observeOemResult` and `desk-bridge.js` on Desk origin.

## Operator smoke (authorized test serial only)

Use only if Owner confirms the device is an authorized test serial.

1. Enable the two mandatory flags above; config cache on prod.
2. Reload hardware dashboard; click Mantra icon on the target row.
3. On Mantra: complete CAPTCHA, click **Check** (manual).
4. Return to Desk tab: expect toast **“OEM result captured — awaiting your review”** and inline panel with warranty / RD expiry dates.
5. **Confirm** or **Reject** explicitly; do not expect automatic service reference assignment unless additional flags are enabled.

## Explicitly not authorized in P-16-41

- Production flag changes, config cache, queue restart, or deploy.
- Morpho capture, expiry persistence, or service-reference suggestion enablement.
- CAPTCHA automation or bypass.