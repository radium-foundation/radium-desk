# Radium Desk OEM Warranty Handoff — IT distribution

## Current release (recommended)

**Package:** `radium-desk-oem-warranty-handoff-0.3.2-for-desk-v4.1.69.zip`  
**Extension version:** **0.3.2** (Manifest V3) — fixes Mantra serial autofill reliability (P-16-21)  
**Matched Desk release:** `v4.1.69` / `50d43ada1`  
**Built:** 2026-11-16

Internal use only. **Not** for Chrome Web Store or public distribution.

### Upgrade from 0.3.1

1. Chrome → **Extensions** → remove or disable the old **0.3.1** unpacked folder (optional but avoids confusion).
2. Extract the **0.3.2** ZIP and **Load unpacked** on folder `radium-desk-oem-warranty-handoff-0.3.2`.
3. Confirm version **0.3.2** on the extension card.

Previous package **`radium-desk-oem-warranty-handoff-0.3.1-for-desk-v4.1.69.zip`** remains in this folder for rollback reference.

## What it does

When an authorized administrator clicks **Check OEM warranty / renewal** on Desk (`https://desk.radiumbox.com`):

1. Opens the correct OEM warranty page (Mantra or Morpho).
2. **Mantra (0.3.2):** waits for the serial field, retries handoff claim if needed, fills serial, shows a green status line reminding staff to complete **CAPTCHA** and **Check**.
3. **Morpho:** DOM-only serial prefill (unchanged).

Desk server-side OEM sessions remain **off** unless Owner enables them separately.

## Installation (Google Chrome)

1. Verify SHA-256 in `SHA256SUMS.txt`.
2. Extract the **0.3.2** ZIP → folder `radium-desk-oem-warranty-handoff-0.3.2` with `manifest.json` + `src/`.
3. Chrome → **Extensions** → **Developer mode** → **Load unpacked** → select that folder.
4. Ensure the extension is **Enabled** and shows **0.3.2**.

## Mantra troubleshooting (0.3.2)

| Message on Mantra page | Meaning |
|------------------------|---------|
| Green: serial filled + CAPTCHA reminder | Success — complete CAPTCHA and **Check** manually |
| Yellow: enter serial manually | Handoff or fill failed — copy serial from Desk |
| Red: form did not load | Refresh page or contact IT |

## Permissions

Unchanged from 0.3.1: `tabs`; host access to Desk, Mantra, Morpho, and local dev origins only.

## Support

Report extension version, Desk release, and whether the status banner appeared (not customer serials unless policy allows).