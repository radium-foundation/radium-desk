# P-17-10 — Morpho E2E readiness and operator checklist

**Risk:** HIGH  
**Release metadata:** tag **`v4.1.78`** @ **`9d3d21107`** (Morpho inline + extension **v0.3.11** in tree).  
**Production (verified 2026-11-17):** **`v4.1.77`** / **`da7081cf8`** — Morpho capture **disabled**.

## Owner authorization (P-17-10)

Owner confirmed authorization **2026-11-17** for the **browser workflow only**:

| Permitted | Not permitted |
|-----------|----------------|
| Approved Morpho portal (`https://rdserviceonline.com/pages/172iserialvalidatenumber`) | CAPTCHA solve, OCR, or auto-submit Check |
| DOM-only serial prefill (`morpho-populate.js`) | Private `OTBSValidatorService` AJAX replay |
| Operator manual CAPTCHA + Check | Server-side `MORPHO_OTBS_LIVE_ENABLED` / live HTTP adapter |
| Read displayed validation result from DOM | Production `DASHBOARD_OEM_HANDOFF_MORPHO_CAPTURE` without separate deploy approval |
| Session-correlated extension → Desk draft | Customer devices, customer serials, assignment completion on prod |
| Desk validation, whitelist, eligible **`AW-DD-MM-YYYY`** REF prefill (IST +3 calendar days) | Production Desk as E2E substitute |

Recorded in Cursor ledger **`RadiumDesk-P-17-10`**. Prior attempts **`RadiumDesk-P-17-08`** / **`P-17-09`** documented gate failures; this row captures preparation plus remaining dependencies.

## Implementation reuse (no second adapter)

| Layer | Location | Notes |
|-------|----------|--------|
| Extension prefill / parse | `tools/oem-warranty-extension/src/content/morpho-fill.js`, `morpho-populate.js`, `morpho-result-parse.js` | DOM-only; Selectize AJAX **disabled** |
| Portal URL gate | `handoff-payload.js` | Must match `172iserialvalidatenumber` path |
| Desk session API | `DashboardOemHandoffSessionService`, journey in `DashboardOemHandoffSessionJourneyTest` | Morpho capture gated by `morpho_result_capture_enabled` |
| Validator / whitelist | `MorphoHandoffResultValidator`, `MorphoWhitelistQualification` | Server-side classification |
| AW REF prefill | `dashboard-oem-rd-expiry-prefill.js`, `MorphoHandoffServiceReferencePrefill` | Parity with Mantra P-17-03 |

## Approved test device

**Status: REQUIRED — not on file.**

| Rule | Detail |
|------|--------|
| Owner must designate | One non-customer Morpho serial explicitly approved for live portal validation |
| Must not use | PHPUnit/Vitest fixtures (e.g. `2441I******`), production customer rows, or invented serials |
| Record when supplied | Append masked serial reference to this doc and ledger row; never commit full serial in git |

**Owner action:** Reply with designated test serial + confirmation it is not assigned to a customer.

## Isolated non-production Desk environment

**Status: NOT AVAILABLE (verified).**

| Source | Verdict |
|--------|---------|
| `docs/desk-rdservice-net-paired-rehearsal-feasibility-p-18-09-54.md` | **NO** documented Desk staging; production KVM **forbidden** for paired/OEM rehearsal |
| Ledger P-21-09-04 | Stopped before staging deploy |
| Local SQLite + `artisan serve` | Disposable for **contract** tests only — **not** verified as isolated OEM E2E target (no Imagick/PDF parity; not owner-designated) |

### Minimum provisioning (when Owner approves infrastructure)

1. Separate MariaDB schema **or** dedicated VM — not `radium_desk` on `187.127.129.16`.
2. Deploy application **≥ `v4.1.78`** (`9d3d21107`).
3. **Temporary** `.env` on that host only:

```dotenv
DASHBOARD_OEM_HANDOFF_ENABLED=true
DASHBOARD_OEM_HANDOFF_MORPHO_CAPTURE=true
MORPHO_OTBS_LIVE_ENABLED=false
MORPHO_OTBS_ADAPTER_DRIVER=mock
```

4. Run migrations for OEM handoff tables; **do not** copy production customer data.
5. Staff Chrome: load unpacked extension from `tools/oem-warranty-extension` (**v0.3.11**) after `npm test`.
6. Authenticated Desk agent session on the **non-prod** base URL only.

## Operator checklist (live E2E — when gates pass)

1. Open service case with approved test serial on **non-prod** Desk.
2. Click Morpho warranty handoff icon; confirm tab opens `rdserviceonline.com/.../172iserialvalidatenumber`.
3. Confirm extension prefilled serial matches session (no URL serial leak).
4. Operator completes portal login if prompted (credentials out of band).
5. Operator focuses/confirms serial field if Submit disabled until interaction.
6. Operator completes CAPTCHA manually; operator clicks **Check** (extension must **not** click Check).
7. After result displays, extension captures DOM fields → submits draft; note `handoff_token` / session id in Desk UI.
8. Return to Desk; confirm draft accepted, whitelist outcome, and REF prefill:
   - **Eligible:** `AW-DD-MM-YYYY` with expiry ≥ today + 3 calendar days (Asia/Kolkata).
   - **Ineligible:** no AW prefill for invalid, mismatched serial, or non-whitelisted outcome.
9. Repeat with **second session** for different serial — confirm stale token cannot complete wrong handoff (journey tests cover server rules; re-verify live).
10. **Do not** click green assignment complete unless Owner test procedure explicitly allows on designated device.

## Automated verification (P-17-10 session)

Executed on worktree **`.worktrees/p17-10-morpho-completion`** @ **`ed99d58fc`**:

| Suite | Result |
|-------|--------|
| Extension `npm test` | **61/61 PASSED** |
| Desk OEM Vitest (`dashboard-oem-*.test.js`) | **42/42 PASSED** |
| PHPUnit Morpho + journey filter | **30/30 PASSED** (~3.5s) |
| Full PHPUnit | **NOT RUN** (Owner-gated) |
| Live browser E2E | **NOT RUN** (missing test device + isolated env) |

## Protected production state

| Check | State |
|-------|--------|
| `release.json` version | **4.1.77** / **da7081cf8** |
| `DASHBOARD_OEM_HANDOFF_MORPHO_CAPTURE` | **absent / false** |
| `MORPHO_OTBS_LIVE_ENABLED` | **false** (default) |
| Deployment / activation this workstream | **NO** |

## Workstream status

**OPEN / BLOCKED** until Owner supplies **(1)** designated test serial and **(2)** verified isolated non-prod Desk, then controlled browser E2E **PASSED**.