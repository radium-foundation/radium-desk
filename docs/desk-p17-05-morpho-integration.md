# P-17-05 — Morpho OEM integration investigation

**Risk:** HIGH  
**Production:** v4.1.77 — Morpho flags remain **disabled**; no live HTTP or production activation in this change set.

## Findings summary

| Item | Status |
|------|--------|
| Official Morpho server API in Desk | **BLOCKED** — `MORPHO_OTBS_LIVE_ENABLED` false; `UnavailableLiveMorphoTransport` refuses outbound calls (P-13-93). |
| OEM validation URL (browser) | **VERIFIED (observed)** — Morpho staff portal uses `https://rdonline.rdms.co.in/OTBSValidatorService/` (Selectize/AJAX). Not an authorized server-to-server contract in this repo. |
| Commercial credentials | **UNKNOWN** — `MORPHO_OTBS_AUTHORIZATION` / `MORPHO_OTBS_SECURITY_STAMP` env slots empty; Owner must supply OEM authorization before live adapter. |
| CAPTCHA / human Check | **VERIFIED** — Extension uses **DOM-only** serial prefill; staff complete validation manually on OEM page (`tools/oem-warranty-extension/README.md`, P-13-90). |
| Capture contract | **VERIFIED** — Extension DOM read (`morpho-result-parse.js`) → `fields.*` JSON → `MorphoHandoffResultValidator` → session `draft_ready`. |
| Desk REF UX (P-17-05 delta) | **IMPLEMENTED** — Morpho `already_whitelisted` + `fields.expiry_date` registers shared inline AW prefill (parity with Mantra P-17-03); no new dashboard panels. |

## Owner actions for live OTBS HTTP (out of scope)

1. Written Morpho/IDEMIA authorization for automated API access.  
2. Official API documentation (headers, rate limits, error codes).  
3. Issued credentials stored only in production `.env` (never in git).  
4. Separate prompt to enable `DASHBOARD_OEM_HANDOFF_MORPHO_CAPTURE` on production.

Mantra CAPTCHA operator workflow: see `docs/desk-p17-05-mantra-captcha-operator-assist.md`.

## P-16-26 / feasibility lineage

**RadiumDesk-P-16-26** (Morpho already-whitelisted → service reference prefill) and **P-13-93** (mock OTBS adapter) established server validation and strict whitelist classification. P-17-05 wires the **authorized** extension path to the Mantra-style inline REF editor without enabling live HTTP or production flags.

## Live E2E completion (P-17-10)

Owner browser-workflow authorization, environment gates, operator checklist, and automated regression evidence: **`docs/desk-p17-10-morpho-e2e-readiness.md`**.