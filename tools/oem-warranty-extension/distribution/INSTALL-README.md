# Radium Desk OEM Warranty Handoff — IT distribution

## Current release (recommended)

**Package:** `radium-desk-v0.3.9.zip`  
**Extracted folder:** `radium-desk-v0.3.9/` (use for **Load unpacked**)  
**Extension version:** **0.3.9** (Manifest V3) — phased session handoff timeouts (P-16-48); requires **`window.postMessage`** bridge from Desk **4.1.71+**  
**Matched Desk release:** **4.1.72+** for phased timeouts; minimum **4.1.71** for session bridge (P-16-43)  
**Built:** 2026-11-16

Internal use only. **Not** for Chrome Web Store or public distribution.

### Distribution filename convention (permanent)

All new IT packages use:

`radium-desk-v{version}.zip`

Examples: `radium-desk-v0.3.7.zip`, `radium-desk-v0.3.8.zip`.  
The ZIP root folder matches: `radium-desk-v{version}/`.

Older packages named `radium-desk-oem-warranty-handoff-0.3.x-for-desk-v4.1.69.zip` remain in this directory for rollback reference only.

### Upgrade from 0.3.6

1. Chrome → **Extensions** → remove or disable the previous unpacked folder.
2. Verify SHA-256 for `radium-desk-v0.3.7.zip` in `SHA256SUMS.txt`.
3. Extract → **Load unpacked** → select `radium-desk-v0.3.7`.
4. Confirm version **0.3.7** on the extension card.

## What it does

When an authorized administrator clicks **Check OEM warranty / renewal** on Desk (`https://desk.radiumbox.com`):

1. Opens the correct OEM warranty page (Mantra or Morpho).
2. **Mantra:** bundled classic script; retry handoff claim, wait for `#txtSerialNo`, fill serial, show status banner (CAPTCHA + **Check** remain manual).
3. **Morpho:** bundled script; DOM serial prefill + optional result capture with strict **already-whitelisted** classification after manual OEM validation.
4. **Two-way (Desk flags ON):** captured results return to Desk for **Confirm** or **Reject**; qualifying Morpho results can open the service-reference review UI (operator must still save manually).

## Installation (Google Chrome)

1. Verify SHA-256 in `SHA256SUMS.txt`.
2. Extract `radium-desk-v0.3.7.zip` → folder `radium-desk-v0.3.7/` with `manifest.json`, `src/`, including `src/content/mantra-fill.bundle.js` and `src/content/morpho-fill.bundle.js`.
3. Chrome → **Extensions** → **Developer mode** → **Load unpacked** → select `radium-desk-v0.3.7`.
4. Ensure **Enabled** and version **0.3.7**.

## Mantra troubleshooting

| Message | Meaning |
|---------|---------|
| Green: serial filled + CAPTCHA reminder | Success — complete CAPTCHA and **Check** manually |
| Yellow: enter serial manually | Handoff or fill failed |
| Red: form did not load | Refresh or contact IT |

If DevTools shows import errors on Mantra, confirm manifest lists `mantra-fill.bundle.js` (not `mantra-fill.js` alone).

## Permissions

Unchanged: `tabs`; host access to Desk, Mantra, Morpho, and local dev origins only.

## Building a new distribution ZIP (maintainers)

From a clean checkout at the target release commit:

1. `cd tools/oem-warranty-extension && npm test` (rebuilds bundles).
2. Stage `manifest.json` and the full `src/` runtime tree (no `node_modules`, `tests`, or dev tooling).
3. Zip as `distribution/radium-desk-v{X.Y.Z}.zip` with root folder `radium-desk-v{X.Y.Z}/`.
4. Append SHA-256 to `SHA256SUMS.txt`; update this README.

No credentials or API keys are bundled.

## Desk server flags

Mantra/Morpho result capture and Desk draft panels require **separate** Owner authorization on the server (not included in this package).

Report issues with package version, SHA-256, browser version, and whether the OEM tab opened (no customer serials in tickets unless policy allows).