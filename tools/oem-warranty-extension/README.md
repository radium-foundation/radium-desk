# OEM Warranty Handoff Extension (Phase A prototype)

Isolated Manifest V3 prototype. **Not for staff distribution.** Desk integration is a separate phase.

## Morpho validation AJAX

Full Selectize sync on the live Morpho page triggers `CheckValidation` and HTTP calls to `https://rdonline.rdms.co.in/OTBSValidatorService/`. **This prototype does not use that path.**

Implemented behavior:

- **DOM-only** population of `#device-serial-number1` and `#device-serial-number1-selectized` without `blur`, without Selectize APIs, and without submitting the form.
- Staff must focus/confirm the field; Submit may remain disabled until they interact and complete OEM client validation manually.
- **Selectize/AJAX integration path: STOPPED** per P-13-90 until Owner accepts client-side OEM validation calls or Morpho provides an official handoff.

## Security

- Handoffs live in the service worker memory only (60s TTL, one-time consume).
- No `chrome.storage`, no serial in URLs, no logging of serial values.
- No `externally_connectable` (not required; Desk will use a content script).
- Desk-origin gated handoffs; serial read from row DOM on explicit click (not URL/storage).
- Morpho remains DOM-only prefill; OEM validation AJAX runs only when staff interact on the OEM page.

## Tests

```bash
cd tools/oem-warranty-extension
npm install
npm test
```

## Load unpacked (developers only)

Chrome → Extensions → Developer mode → Load unpacked → select this directory.
