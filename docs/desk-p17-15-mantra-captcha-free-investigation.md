# P-17-15 — Mantra validation automation (CAPTCHA-free investigation)

**Risk:** HIGH — OEM integration and device validation  
**Cursor ledger:** `RadiumDesk-P-17-15`  
**Worktree:** `.worktrees/p17-15-mantra-captcha-free` @ `origin/main` **`098e67f30`** (PR **#309** merged)  
**Production baseline (verified 2026-11-17):** Desk **v4.1.79** / build **`c813c2f74`**; OEM flags `DASHBOARD_OEM_HANDOFF_ENABLED=true`, `DASHBOARD_OEM_HANDOFF_MANTRA_CAPTURE=true`; extension distributable **v0.3.12** (PR **#309**).  
**Production deployment (this prompt):** **NO**

## 1. Objective

Reduce or eliminate **manual CAPTCHA interaction** in the Mantra warranty check workflow **only** through an **official, documented, CAPTCHA-free** OEM mechanism. CAPTCHA solving, OCR, third-party solvers, undocumented endpoints, and automated CAPTCHA submission remain **out of scope** (see P-17-05 operator-assist doc).

## 2. Existing workflow (preserved — do not rebuild)

| Step | Component | Status |
|------|-----------|--------|
| Session creation | Desk `dashboard-oem-warranty-handoff.js` → POST `.../oem-handoff/sessions` | **VERIFIED** (PHPUnit journey tests) |
| Extension handoff | `OEM_HANDOFF_REQUEST` + `handoff_token` / session id | **VERIFIED** (P-16-43 bridge, P-16-48 latency) |
| Portal open | `https://servico.mantratecapp.com/MasterForms/RDWarrantyCheck.aspx` | **VERIFIED** (`handoff-payload.js`) |
| Serial prefill | `mantra-fill` / populate (no submit) | **VERIFIED** |
| CAPTCHA focus + highlight | `mantra-operator-assist.js` → `#txtCaptcha` | **VERIFIED** (P-17-05, PR **#303**) |
| CAPTCHA text-entry detection | `watchMantraCaptchaEntry` + late DOM re-bind | **VERIFIED** (ext **v0.3.12**, PR **#309**) |
| Manual Check | Operator clicks `#btnCheck` — extension **never** clicks Check | **VERIFIED** (policy + tests) |
| Result parse | `#TblRDWarrantyCheck` → `mantra-result-parse.js` | **VERIFIED** (vitest) |
| Draft + correlation | Background → Desk `.../draft` with session token | **VERIFIED** |
| Validator + idempotency | `MantraHandoffResultValidator` | **VERIFIED** (PHPUnit) |
| AW / REF workflow | P-17-01 / P-17-03 inline prefill after confirm | **VERIFIED** (Morpho path unchanged) |

There is **no** Desk server-side Mantra HTTP adapter (contrast: Morpho mock OTBS / `MORPHO_OTBS_*` env slots in `desk-p17-05-morpho-integration.md`). All Mantra capture is **browser DOM** after human CAPTCHA + Check.

**Manual fallback:** Current operator-assist path remains the **only authorized** production path until Mantra supplies a CAPTCHA-free contract.

## 3. CAPTCHA-free options investigated

| Option | Evidence | Verdict | Confidence |
|--------|----------|---------|------------|
| Public Mantra warranty / RD validation REST API | No endpoint, auth, or schema in repo; public Mantra/Servico pages describe RMA, RD Service drivers/SDKs, partner portal login — **not** warranty lookup API | **Not available without OEM contract** | **VERIFIED** (absence in repo + public web review) |
| Partner “Technology Partner” SDK/API (mantratec.com) | Marketing claims “API documentation” for **device/biometric** integration (Aadhaar RD, MFS100 SDK) | **Not evidenced for Servico RDWarrantyCheck** | **INFERRED** (wrong product surface) |
| Servico `RDWarrantyCheck.aspx` bulk Excel upload (≤50 serials) | Public page title mentions upload; requires **partner session**; CAPTCHA behaviour behind login **not** verified | **Possible batch path only with partner account** — not CAPTCHA-free or machine API | **UNKNOWN** |
| CAPTCHA accessibility (audio / exempt field) | Not in extension selectors or runbooks; P-17-05 marked **UNKNOWN** | **Not implemented**; needs live page inspection with Owner | **UNKNOWN** |
| Enterprise / partner CAPTCHA exemption on web form | No written agreement or technical note in repository | **Blocked on Mantra authorization** | **UNKNOWN** |
| Morpho-style server adapter in Desk | Pattern exists for Morpho; **no** `MANTRA_*` transport, config, or validator HTTP path | **Not started** — intentional | **VERIFIED** |

**Web references (external, non-contractual):**

- Servico RD warranty page: `https://servico.mantratecapp.com/MasterForms/RDWarrantyCheck.aspx`
- Servico support: servico@mantratec.com, +91-079-49068000
- Mantra warranty/RMA process: `https://www.mantratec.com/Warranty-and-RMA-Process`

## 4. Recommended path (if Mantra authorizes)

Reuse existing **session + draft** architecture (same as Morpho capture), adding **only**:

1. Official API base URL, auth scheme, and rate limits from Mantra.
2. A single Desk transport (e.g. `MantraWarrantyValidationTransport`) called from handoff session flow **or** a guarded “validate” action — **not** duplicating `MantraHandoffResultValidator` field rules without OEM field mapping doc.
3. Map OEM JSON → existing draft payload shape (`serial`, `rd_expiry_date`, etc.) already consumed by `MantraHandoffResultValidator`.
4. Feature flag default **OFF** until credentials in production `.env` (mirroring `MORPHO_OTBS_LIVE_ENABLED`).
5. Keep extension DOM path as **fallback** when API disabled or errors.

**No code committed** in P-17-15 — dependencies below are unfilled.

## 5. Required OEM credentials / authorization

| Dependency | Owner / Mantra action |
|------------|------------------------|
| Written authorization for automated warranty + RD subscription lookup (not browser scraping) | Mantra legal/IT + Radium Owner |
| API or SDK documentation (request/response, errors, supported models) | Mantra |
| Partner code / API key / OAuth / IP allowlist (as specified by Mantra) | Mantra → Owner → KVM `.env` |
| CAPTCHA-free assurance for chosen channel (API, partner bulk, or portal exemption) | Mantra |
| Non-production test serials + SLA | Mantra / Owner |
| DPA / data-processing terms if serials hit server-side API | Owner |

## 6. Technical enquiry

Draft email and question list: **`docs/desk-p17-15-mantra-captcha-free-enquiry.md`** (send from Owner to servico@mantratec.com).

## 7. Tests and regressions (this prompt)

| Suite | Result | Notes |
|-------|--------|-------|
| Extension vitest | **66/66 PASS** @ `098e67f30` worktree | No extension code changes |
| Desk OEM vitest | Not re-run | No Desk code changes |
| PHPUnit Mantra journey | Not re-run | No PHP changes; full suite Owner-gated |
| Live Mantra portal E2E | **NOT RUN** | Would not prove CAPTCHA-free API |

## 8. Related PRs

| PR | Merge SHA | Relevance |
|----|-----------|-----------|
| **#303** | `9d3d21107` | Mantra CAPTCHA operator assistance (v0.3.11) |
| **#308** | `fd1185d9` | Extension package v0.3.11 distribution |
| **#309** | `098e67f30` | CAPTCHA entry detection (v0.3.12) |

## 9. Success criterion (this task)

**Met:** Definitive documented finding — **no authorized CAPTCHA-free Mantra mechanism exists in repository or public OEM docs**; implementation **blocked** until Mantra provides contract + credentials. Existing manual workflow + v0.3.12 operator assist **unchanged**.