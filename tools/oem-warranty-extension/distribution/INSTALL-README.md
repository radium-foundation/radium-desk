# Radium Desk OEM Warranty Handoff — IT distribution

## Current release (recommended)

**Package:** `radium-desk-oem-warranty-handoff-0.3.3-for-desk-v4.1.69.zip`  
**Extension version:** **0.3.3** (Manifest V3) — fixes Mantra ES module load error on Chrome (P-16-22)  
**Matched Desk release:** `v4.1.69` / `50d43ada1`  
**Built:** 2026-11-16

Internal use only. **Not** for Chrome Web Store or public distribution.

### Upgrade from 0.3.2

**0.3.2** may show `Cannot use import statement outside a module` on the Mantra page. Upgrade to **0.3.3**:

1. Chrome → **Extensions** → remove or disable the **0.3.2** unpacked folder.
2. Extract the **0.3.3** ZIP → **Load unpacked** → folder `radium-desk-oem-warranty-handoff-0.3.3`.
3. Confirm version **0.3.3** on the extension card.

Older packages **0.3.1** and **0.3.2** remain in this folder for rollback reference only.

## What it does

When an authorized administrator clicks **Check OEM warranty / renewal** on Desk (`https://desk.radiumbox.com`):

1. Opens the correct OEM warranty page (Mantra or Morpho).
2. **Mantra:** bundled classic script (no ES imports on the page); retry handoff claim, wait for `#txtSerialNo`, fill serial, show status banner (CAPTCHA + **Check** remain manual).
3. **Morpho:** DOM-only serial prefill (unchanged).

## Installation (Google Chrome)

1. Verify SHA-256 in `SHA256SUMS.txt`.
2. Extract the **0.3.3** ZIP → `radium-desk-oem-warranty-handoff-0.3.3/` with `manifest.json`, `src/`, including `src/content/mantra-fill.bundle.js`.
3. Chrome → **Extensions** → **Developer mode** → **Load unpacked** → select that folder.
4. Ensure **Enabled** and version **0.3.3**.

## Mantra troubleshooting

| Message | Meaning |
|---------|---------|
| Green: serial filled + CAPTCHA reminder | Success — complete CAPTCHA and **Check** manually |
| Yellow: enter serial manually | Handoff or fill failed |
| Red: form did not load | Refresh or contact IT |

If DevTools still shows import errors on Mantra, confirm the loaded extension is **0.3.3** and manifest lists `mantra-fill.bundle.js` (not `mantra-fill.js`).

## Permissions

Unchanged: `tabs`; host access to Desk, Mantra, Morpho, and local dev origins only.