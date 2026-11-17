# P-17-05 — Mantra CAPTCHA workflow investigation and operator assistance

**Production:** v4.1.77 — **no deploy**; extension **v0.3.11** (source only until IT redistribution).

## A. CAPTCHA implementation (inspect)

| Question | Finding | Confidence |
|----------|---------|------------|
| CAPTCHA type | **Image challenge** with **text entry field** (`#txtCaptcha` in tests and integration fixtures). Operator transcribes visible characters into a text box. | **VERIFIED** (DOM contract in extension tests; live ASP.NET `RDWarrantyCheck.aspx`) |
| Audio / accessibility alternative | **UNKNOWN** — not present in extension selectors or runbooks; must be verified on live Mantra page with Owner/IT. | **UNKNOWN** |
| Copyable challenge text | **INFERRED: No** — typical image CAPTCHA is not copyable; extension does **not** read image nodes. | **INFERRED** |
| Official API without interactive CAPTCHA | **UNKNOWN / not authorized in repo** — no Mantra server-to-server contract; Desk uses browser portal only. | **UNKNOWN** |
| Enterprise CAPTCHA-free authorization | **UNKNOWN** — requires written Mantra/IDEMIA agreement; **not** evidenced in repository. | **UNKNOWN** |

**OEM URL:** `https://servico.mantratecapp.com/MasterForms/RDWarrantyCheck.aspx` (extension `handoff-payload.js`).

## B. Operator assistance options

| # | Option | Feasibility | Operator effort | Extension permissions | Terms / security |
|---|--------|-------------|-----------------|---------------------|------------------|
| 1 | Open Mantra page + populate serial | **VERIFIED — already implemented** | Click Mantra icon → 0 keystrokes for serial | `tabs`, host `servico.mantratecapp.com` | DOM prefill only; no submit |
| 2 | Copy serial to clipboard | **IMPLEMENTED (v0.3.11)** — button on success banner (user click) | +1 click optional | Clipboard API on **user gesture** | Does not expose captcha |
| 3 | Focus CAPTCHA input | **IMPLEMENTED** — `#txtCaptcha` focus + scroll | Saves 1 tab/focus | Content script on Mantra host | Does not set captcha value |
| 4 | Visual “CAPTCHA required” cue | **IMPLEMENTED** — banner steps + blue outline on field | None extra | Same | No bypass |
| 5 | Detect CAPTCHA **entry** (non-empty) | **IMPLEMENTED** — `input` listener on text field only | None extra | Same | Does **not** verify correctness |
| 6 | Keep **Check** manual | **VERIFIED** — extension never clicks `btnCheck` | Operator clicks Check | — | Required by policy |
| 7 | Detect validation + capture fields | **VERIFIED** — `observeOemResult` on `#TblRDWarrantyCheck` | Operator returns to Desk manually or via tab focus after capture | `tabs` messaging | DOM read post-Check only |
| 8 | Return to Desk + REF prefill | **VERIFIED** — background focuses Desk tab after draft; P-17-03 AW inline prefill when capture enabled | 1 tab switch (auto focus) | `tabs` | Mantra unchanged; Morpho **disabled** |
| 9 | Clipboard for “copyable CAPTCHA text” | **NOT APPLICABLE** — challenge treated as image; **no** image OCR | — | — | Restricted per P-17-05 |

### Blocked / out of scope approaches

| Approach | Status |
|----------|--------|
| OCR / third-party CAPTCHA solving | **BLOCKED** |
| Auto-submit Check / captcha autofill | **BLOCKED** |
| Undocumented auth headers / scraping | **BLOCKED** |
| Live Morpho OTBS HTTP without Owner credentials | **BLOCKED** (see `desk-p17-05-morpho-integration.md`) |

## C. Recommended operator workflow

1. Desk → click **Mantra** on service case (session created when flags on).  
2. Mantra tab opens; serial **prefilled**; **CAPTCHA field focused** and highlighted.  
3. Operator **reads image**, types into CAPTCHA, clicks **Check** (manual).  
4. Extension observes result table → posts draft → **Desk tab focused**.  
5. Operator completes **green tick** on REF (AW prefill when eligible); **no auto-save**.

## D. Unresolved Owner questions

1. Does Mantra offer an **official CAPTCHA-free** or **API** path for licensed integrators?  
2. Is there a documented **accessibility** (audio) CAPTCHA on `RDWarrantyCheck.aspx`?  
3. Written approval to store/use **API credentials** if a non-interactive route exists?

## E. Technical dependencies

- Internal extension **v0.3.11+** (`mantra-fill.bundle.js` rebuilt).  
- Production flags unchanged: `DASHBOARD_OEM_HANDOFF_MORPHO_CAPTURE` **false**.  
- Mantra two-way capture still requires `DASHBOARD_OEM_HANDOFF_ENABLED` + `MANTRA_CAPTURE` (not changed in this task).