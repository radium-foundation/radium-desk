# P-11-45 — Unified OEM warranty / RD validity checker

Read-only permission notes (no vendor contact):

| Provider | Automated DOM extraction | CAPTCHA (verified 2026-11-11) | Server API use |
|----------|-------------------------|-------------------------------|----------------|
| MantraTec | Staff-browser only after human CAPTCHA on official form; no `CheckRDWarranty` PageMethod | Yes (`txtCaptcha` + image) | **Not authorized** |
| Morpho / IDEMIA | Staff-browser only after staff clicks portal **Submit**; no Shopify/XHR endpoints | Not on serial page HTML; Shopify hCaptcha bootstrap present site-wide (**UNKNOWN** on submit) | **Not authorized** |

Desk never calls provider hosts from the server. Draft submission uses handoff tokens bound to user, order, provider, and serial.

**P-11-49:** `OEM_WARRANTY_EXTENSION_DOM_ENABLED` defaults to **false** (manual-first). Extension host patterns narrowed to production `desk.radiumbox.com` and explicit dev origins only.
