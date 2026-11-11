# Radium Desk OEM Warranty Helper (Chrome MV3)

Local-only helper for staff browsers. It does not call undocumented APIs, store Desk passwords, or use broad host permissions.

## Install (developer mode)

1. Open `chrome://extensions`.
2. Enable **Developer mode**.
3. Choose **Load unpacked** and select this directory.
4. Copy the extension ID into Desk `.env` as `OEM_WARRANTY_CHROME_EXTENSION_ID`.

## Permissions

- Desk hosts only: `http://localhost`, `http://127.0.0.1`, `https://*.radiumdesk.test`, `https://desk.radiumbox.com`
- `https://servico.mantratecapp.com/*`
- `https://rdserviceonline.com/*`

## Behaviour

1. Desk order workspace starts a short-lived lookup session and messages the extension.
2. Extension opens the official provider portal and prefills the serial number.
3. Staff completes CAPTCHA / verification on the provider site.
4. When Desk enables `OEM_WARRANTY_EXTENSION_DOM_ENABLED`, after visible results render the extension may read the on-page table and return a draft to Desk through the Desk tab (no server-side provider requests).
5. Staff review and confirm in Desk. By default, DOM capture is disabled — use manual entry.

## Manual fallback

If the extension is unavailable, Desk still opens the portal (or staff can open it manually) and supports manual result entry.
