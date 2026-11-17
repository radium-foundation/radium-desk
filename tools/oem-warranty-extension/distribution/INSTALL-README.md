# Radium Desk OEM Warranty Handoff — IT distribution

## Current release (recommended)

**Package:** `radium-desk-v0.3.11.zip`  
**Extracted folder:** `radium-desk-v0.3.11/` (use for **Load unpacked**)  
**Extension version:** **0.3.11** (Manifest V3) — Morpho DOM prefill + result capture; Mantra CAPTCHA operator assist (P-17-05 / #303)  
**Matched Desk release:** **v4.1.79+** with `DASHBOARD_OEM_HANDOFF_MORPHO_CAPTURE=true` on production (Owner-activated P-17-12)  
**Built:** 2026-11-17 (P-17-13)

Internal use only. **Not** for Chrome Web Store or public distribution.

**Do not** install `radium-desk-v0.3.10.zip` or older for Morpho two-way capture — use **0.3.11** only.

### Distribution filename convention (permanent)

All new IT packages use:

`radium-desk-v{version}.zip`

Examples: `radium-desk-v0.3.11.zip`, `radium-desk-v0.3.10.zip`.  
The ZIP root folder matches: `radium-desk-v{version}/`.

Older packages named `radium-desk-oem-warranty-handoff-0.3.x-for-desk-v4.1.69.zip` remain in this directory for rollback reference only.

### Upgrade from 0.3.10 or earlier

1. Chrome → **Extensions** → remove or disable the previous unpacked folder.
2. Verify SHA-256 for `radium-desk-v0.3.11.zip` in `SHA256SUMS.txt`.
3. Extract → **Load unpacked** → select `radium-desk-v0.3.11`.
4. Confirm version **0.3.11** on the extension card.

## What it does

When an authorized administrator clicks **Check OEM warranty / renewal** on Desk (`https://desk.radiumbox.com`):

1. Opens the correct OEM warranty page (Mantra or Morpho).
2. **Mantra:** bundled classic script; retry handoff claim, wait for `#txtSerialNo`, fill serial, CAPTCHA assist (focus/highlight); **Check** remains manual.
3. **Morpho:** portal `https://rdserviceonline.com/pages/172iserialvalidatenumber`; **DOM-only** serial prefill; no Selectize/AJAX replay; operator completes CAPTCHA and **Check** manually; extension reads displayed result when capture is enabled.
4. **Two-way (Desk flags ON):** session-correlated draft to Desk; Morpho whitelist → inline **AW-DD-MM-YYYY** REF prefill when eligible (Desk **4.1.79+**).

## Installation (Google Chrome)

1. Verify SHA-256 in `SHA256SUMS.txt` (line for `radium-desk-v0.3.11.zip`).
2. Extract `radium-desk-v0.3.11.zip` → folder `radium-desk-v0.3.11/` with `manifest.json` and `src/` (includes `mantra-fill.bundle.js` and `morpho-fill.bundle.js`).
3. Chrome → **Extensions** → **Developer mode** → **Load unpacked** → select the extracted `radium-desk-v0.3.11` folder.
4. Ensure **Enabled** and version **0.3.11**.

## Mantra troubleshooting

| Message | Meaning |
|---------|---------|
| Handoff expired | Re-click the Desk OEM icon (60s TTL in extension). |
| Serial field not found | Refresh Mantra page; confirm URL matches RDWarrantyCheck. |
| CAPTCHA required | Complete captcha manually; extension does not submit Check. |

If DevTools shows import errors on Mantra, confirm manifest lists `mantra-fill.bundle.js` (not `mantra-fill.js` alone).

## Morpho troubleshooting

| Message | Meaning |
|---------|---------|
| Wrong portal | Must land on `172iserialvalidatenumber` path; Desk opens URL via handoff only. |
| Serial not filled | Focus serial field on portal; DOM prefill does not call OTBS HTTP. |
| Draft not in Desk | Confirm production `DASHBOARD_OEM_HANDOFF_MORPHO_CAPTURE=true` and extension **0.3.11**. |

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

Mantra/Morpho result capture requires Owner-authorized server flags (production Morpho capture enabled P-17-12). This package does not change server configuration.

Report issues with package version, SHA-256, browser version, and whether the OEM tab opened (no customer serials in tickets unless policy allows).