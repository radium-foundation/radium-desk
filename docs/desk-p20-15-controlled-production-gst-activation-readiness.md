# P-20-15 — Controlled production GST activation readiness

**Task:** P-20-15  
**Cursor ledger:** `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef`  
**Scope:** Radium Desk production readiness **only** — **no activation in this document’s authoring task**

## Authorization state

| Gate | Status |
| --- | --- |
| Owner business matrix (P-20-13) | **10/10 APPROVED** (Owner-designated Finance/CA) |
| Engineering matrix (P-20-14) | **10/10 VERIFIED** (PHPUnit) |
| Owner authorizes P-20-15 readiness work | **YES** |
| Owner authorizes **production flag activation** | **NO** — separate explicit instruction required |
| Production **known-good** with flags ON | **Not claimed** — protected journeys **NOT RUN** on prod |

---

## Production baseline (read-only evidence)

Captured **2026-11-20** via SSH to production app root (no `.env` secrets disclosed).

| Item | Class | Value |
| --- | --- | --- |
| `release.json` | **VERIFIED** | **v4.1.102** / **`339e3dc99`**, deployed **2026-11-20 08:41:35 IST** |
| `origin/main` (repo) | **VERIFIED** | **`4943e775e`** (P-20-14 docs + regression; **not deployed** to prod) |
| `STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED` | **VERIFIED** | Absent from `.env` → runtime **`false`** |
| `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED` | **VERIFIED** | Absent → runtime **`false`** |
| Buyer verification provider | **VERIFIED** | `whitebooks` (config default) |
| GST migrations | **VERIFIED** | `120000` **[66] Ran**, `130000` **[67] Ran**, `140000` **[68] Ran** |
| Receipt publisher | **VERIFIED** | `enabled=true`, `schedule_on_issue=true`, allowlist **8** IDs |
| GST flags ↔ receipt publisher | **INFERRED** | **Independent** — enabling buyer-GST flags does not change allowlist; **no** allowlist expansion in GST rollout |

### RIP checkpoints

| Checkpoint | `rip:verify-checkpoint` |
| --- | --- |
| PRE `RIP-201126-0836-…-7edf904` | **PASS** (`checksum_ok=true`) |
| POST `RIP-201126-0843-…-339e3dc` | **PASS** (`checksum_ok=true`) |

---

## Recovery and backup position

| Item | Class | Assessment |
| --- | --- | --- |
| Latest on-host backup dir | **VERIFIED** | **`20261120T025830Z`** (newest under `/var/backups/radium-desk/runs/`) |
| Manifest application build | **VERIFIED** | **`7edf904bb`** (pre–**v4.1.102** deploy) |
| Disposable restore proof | **VERIFIED** | P-20-09 drill **`radium_desk_p2009_drill`** — decrypt/import **exit 0**, structural parity **198** tables (pre-migration **120000**) |
| Backup **after** P-20-10 deploy **`339e3dc99`** + migrations **66–68** | **BLOCKED** | **Not evidenced** — latest backup predates deploy (~08:28 IST vs deploy ~08:41 IST) |
| Post-deploy restore suitability for flag rollout | **INFERRED** | Same `bin/backup-run.sh` pipeline + P-20-09 methodology applies; **Owner must authorize fresh backup + optional drill before Stage A** |

**Do not** treat “manifest file exists” as restore proof without decrypt/structural drill (per P-20-09 and `RADIUMWEBSITES_TESTING_AND_VALIDATION_POLICY.md`).

---

## Technical activation dependencies

### Feature flags (exact env names)

```bash
STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED=false   # current production
STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED=false     # current production
# Optional override (default whitebooks):
# STATUTORY_BUYER_GSTIN_VERIFICATION_PROVIDER=whitebooks
```

Config mapping: `config/statutory_invoices.php` → `buyer_gstin_verification.enabled` / `b2c_fallback.enabled`.

### Why Stage A before Stage B

| Topic | Stage A (verification ON, fallback OFF) | Stage B (fallback ON) |
| --- | --- | --- |
| Live WhiteBooks GET `/public/search` | Only when **`requiresProviderVerification`** — valid GSTIN **and** billing state ≠ GSTIN state (`CommerceOrderBuyerGstStateAlignment`) | Same lookup path; failures may trigger **`ensureAutomaticConversion`** |
| Auto B2C conversion | **Disabled** — `BuyerGstB2cConversionPolicy::fallbackPolicyEnabled()` false | **Enabled** for eligible verification outcomes |
| Provider timeout / 5xx | Mint may **hold** (`ReviewRequired` / pending) — **no** auto B2C | Auto B2C with technical remark (matrix #5) |
| Missing GSTIN | Native B2C — **no** provider call (matrix #4, P-20-14) | Unchanged |
| Audit tables | `commerce_order_buyer_gst_verifications` may grow | + `commerce_order_buyer_gst_b2c_conversions` on auto/manual B2C |

**Stage B without Stage A stability** risks coupling provider noise with automatic invoice classification changes.

### Provider and credentials

| Dependency | Class | Notes |
| --- | --- | --- |
| WhiteBooks buyer GST API | **INFERRED** | `WhitebooksBuyerGstinVerificationGateway`; timeouts logged — **no** prod call in P-20-15 |
| GSP / e-invoice credentials on prod | **VERIFIED** (presence) | Present for IRN stack; buyer lookup uses buyer-verification client — **does not** require IRN activation for Stage A/B |
| `STATUTORY_EINVOICE_WORKER_MAY_MINT` / IRN policy | **VERIFIED** | Separate from buyer-GST flags; **do not** enable IRN expansion as part of GST flag rollout unless Owner authorizes |

### Runtime apply pattern (when authorized)

1. Edit production `.env` (flag values only — no code deploy required for flags on **`339e3dc99`**).
2. `php artisan config:cache`
3. Restart **`radium-desk-queue-worker`** (and web PHP workers per prior deploy pattern).
4. **Do not** run `migrate` — schema **66–68** already **Ran**.

### Queue, scheduler, receipt interaction

| Component | Class | Interaction with GST flags |
| --- | --- | --- |
| Statutory mint | **VERIFIED** (code) | `CommerceBuyerGstMintGate` runs before commerce mint; may call `ensureAutomaticConversion` when fallback ON |
| E-invoice outbox / worker | **INFERRED** | B2C classifications exclude IRN (`b2c_not_eligible` — PHPUnit) |
| RadiumBox receipt publication | **VERIFIED** | `RadiumBoxStatutoryReceiptPublicationOutboxWriter::enqueue` on issue; gated by **allowlist** — GST flags do not widen allowlist |
| Receipt backlog processor | **INFERRED** | Existing scheduler; monitor `radiumbox_statutory_receipt_publications` — unrelated to GST flag enable order |

---

## Two-stage activation runbook (for separate Owner execution)

### Global preconditions (both stages)

| # | Gate | Pass criterion |
| --- | --- | --- |
| G1 | Production release | **`339e3dc99`** / **v4.1.102** (or newer **Owner-approved** deploy containing GST migrations) |
| G2 | Migrations **66–68** | **Ran** on `radium_desk` |
| G3 | Fresh encrypted backup | **After** current prod SHA + schema; manifest + optional disposable drill |
| G4 | POST-RIP | Verify **`RIP-201126-0843-…-339e3dc`** (or successor) **PASS** |
| G5 | Owner written authorization | Distinct instruction per stage (verification vs fallback) |
| G6 | `main` doc-only drift | **`4943e775e`** is **not** on prod — **no deploy required** for flags unless Owner chooses to ship doc-only commits |

### Stage A — verification ON, fallback OFF

**Set:** `STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED=true`, `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED=false` (or unset).

| Step | Action |
| --- | --- |
| A1 | Capture PRE-activation RIP (recommended) |
| A2 | Apply env + `config:cache` + worker restart |
| A3 | Confirm runtime config via read-only tinker (both flags expected: verification **true**, fallback **false**) |
| A4 | Run **protected journeys** (see below) — minimum subset for Stage A |
| A5 | Observe **24–72 h** (or Owner-agreed window) with monitoring queries |

**Stage A pass gates (all required before Stage B authorization):**

| ID | Gate | Pass | Fail / stop |
| --- | --- | --- | --- |
| A-P1 | Config effective | `verification=true`, `b2c_fallback=false` | Mis-set env → rollback A-R1 |
| A-P2 | No unexpected auto B2C | `commerce_order_buyer_gst_b2c_conversions` count **unchanged** except manual pre-existing rows | Any automatic conversion row → **STOP**, A-R1, incident review |
| A-P3 | State-match B2B mint | Representative order: GSTIN state = billing state → mint **unchanged** vs baseline | Regression → A-R1 |
| A-P4 | Missing GSTIN mint | Native B2C, blank invoice GSTIN (matrix #4) | Wrong B2B / conversion row → A-R1 |
| A-P5 | State-mismatch path | Provider row created; pending/review blocks mint until resolved (**no** auto B2C) | False “invalid” without provider evidence → A-R1 |
| A-P6 | Provider error handling | Timeout/5xx → **hold**, not B2C invoice (fallback OFF) | Auto B2C mint → **STOP** fallback mis-flag |
| A-P7 | IRN | No new unintended B2C IRN attempts for cases exercised | B2B IRN regression → escalate |
| A-P8 | Receipt allowlist | Count **8**, no new allowlist IDs | Any allowlist change → **STOP** (out of scope) |
| A-P9 | Duplication | Repeat mint → single invoice (idempotency) | Duplicate statutory rows → freeze mints, Owner Finance |

**Stage A rollback (A-R1):** Set `STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED=false` (unset), `config:cache`, worker restart. **Does not** delete verification rows or issued invoices.

### Stage B — fallback ON (only after Stage A pass)

**Set:** `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED=true` (verification remains **true**).

| Step | Action |
| --- | --- |
| B1 | Owner **second** authorization referencing Stage A evidence |
| B2 | Apply env + `config:cache` + worker restart |
| B3 | Protected journeys for conversion cases (#2, #3, #5, #6, #7) |
| B4 | Observe conversion rate, remark classes, B2B block rate |

**Stage B pass gates:**

| ID | Gate | Pass | Fail / stop |
| --- | --- | --- | --- |
| B-P1 | Config | Both flags **true** | — |
| B-P2 | Inactive GSTIN | Suspended/cancelled → auto B2C, blank invoice GSTIN, audit row (matrix #2) | B2B with inactive → B-R1 |
| B-P3 | Invalid GSTIN | Auto B2C or documented hold per policy; order retains submitted GSTIN (matrix #3) | — |
| B-P4 | Provider technical failure | Auto B2C + technical remark; **no** false invalidity claim (#5) | B-R1 |
| B-P5 | Product/service B2C | `CommerceOrderBuyerGstProductB2cIssuanceTest` behaviours; service conversion link (#6/#7) | Tax/POS drift → B-R1 |
| B-P6 | Verified active B2B | Unchanged (#1, #8) | — |
| B-P7 | Post-mint | No new conversion after issue | — |
| B-P8 | Concurrency | No duplicate invoices/conversions under retry (#9) | — |

**Stage B rollback (B-R1):** Set `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED=false` first (stops new auto B2C). If provider instability: also set verification **false**. **Never** delete statutory invoices or rewrite conversion history.

---

## Monitoring and stop conditions

### SQL (metadata counts only — no customer export)

```sql
SELECT verification_status, COUNT(*) FROM commerce_order_buyer_gst_verifications GROUP BY verification_status;
SELECT status, COUNT(*) FROM commerce_order_buyer_gst_b2c_conversions GROUP BY status;
SELECT status, COUNT(*) FROM radiumbox_statutory_receipt_publications GROUP BY status;
```

### Stop triggers (immediate flag disable)

| Signal | Stage | Action |
| --- | --- | --- |
| Sustained WhiteBooks 5xx/timeout spike | A or B | Disable **verification**; preserve rows |
| Unexpected `b2c_conversions` growth with fallback OFF | A | **STOP** — misconfiguration |
| Auto B2C on active verified B2B | B | Disable **fallback**; Finance review |
| Duplicate `statutory_invoices` for same commerce source | A/B | Ops freeze mint; idempotency investigation |
| Tax rate or POS mismatch on issued invoice | B | Disable **fallback**; no invoice rewrite |
| Receipt `failed_permanent` spike for allowlisted IDs | either | Receipt triage — **separate** from GST rollback |
| Audit integrity concern (missing verification row when lookup ran) | A/B | Disable verification; engineering incident |

### Logs (non-PII)

- `buyer_gstin_verification.whitebooks_timeout`
- `buyer_gstin_verification.whitebooks_error`

---

## Protected journeys — prod vs UAT

| Matrix # | Case | Stage A prod (with Owner mint auth) | Stage B prod | UAT-first recommended |
| --- | --- | --- | --- | --- |
| 1 | Verified active B2B | **Yes** — no provider if states match | **Yes** | Optional |
| 2 | Inactive GSTIN | **Hold only** (fallback OFF) | **Yes** — auto B2C | **Yes** before B |
| 3 | Invalid GSTIN | **Hold** / local block | **Yes** | **Yes** before B |
| 4 | Missing GSTIN | **Yes** — native B2C | **Yes** | Optional |
| 5 | Provider failure | **Yes** — observe hold | **Yes** | **Yes** before B |
| 6 | Product B2C conversion | Manual only (fallback OFF) | **Yes** | UAT P-20-05 + prod pilot |
| 7 | Service B2C | Manual only | **Yes** | UAT P-20-01 pattern |
| 8 | State mismatch verified | **Yes** — live lookup | **Yes** | Controlled prod OK with Owner |
| 9 | Duplicate / concurrent | **Yes** — idempotent mint | **Yes** | MariaDB harness N/A on prod |
| 10 | Receipt publication | **Yes** — allowlist only | **Yes** — **no** allowlist change | N/A |

**Production safe without minting:** config reads, migration status, RIP verify, table **COUNT(*)**, allowlist length.

**Any journey that triggers WhiteBooks or issues a statutory invoice on prod** requires **explicit Owner approval for live provider calls and/or production minting** (outside P-20-15).

---

## Rollback matrix (no statutory record deletion)

| Scenario | Order | Effect | Limitation |
| --- | --- | --- | --- |
| Stage B issues | 1) fallback OFF 2) verification OFF if needed | Stops new conversions/lookups | Issued B2C/B2B invoices stand |
| Stage A issues | verification OFF | Stops lookups | Pending verification rows remain |
| Code rollback to **v4.1.101** | **Not recommended** with schema **66–68** | Risk code/schema skew | Prefer flags OFF on **v4.1.102** |
| DB restore pre-deploy backup | Owner DR only | Reverts DB to **`7edf904bb`** era | **Drops** post-backup data; GST tables empty/missing per P-20-09 drill |

---

## Crosswalk — P-20-13 / P-20-14 matrix (unchanged business approval)

Engineering evidence citations remain in `docs/desk-p20-13-gst-approval-matrix-validation.md` (case **#4** **VERIFIED** via P-20-14). This runbook does **not** alter approval status.

PHPUnit bundles to re-run **before** Owner activation (focused only):

- `CommerceOrderMissingBuyerGstinNativeB2cTest`
- `CommerceOrderBuyerGstB2cFallbackTest`
- `CommerceOrderBuyerGstProductB2cIssuanceTest`
- `CommerceOrderBuyerGstVerificationTest`
- `RadiumBoxStatutoryReceiptPublicationTest`

---

## Explicit non-actions (P-20-15 task)

**NO — Not performed:** production deploy, flag activation, live GST provider calls, production invoice minting, IRN generation, receipt allowlist expansion, schema/data changes.

---

## Next Owner decision (production activation)

Owner must issue **separate, explicit** written authorization that includes:

1. **Fresh production backup** (post-**`339e3dc99`**) + optional restore drill.  
2. **Stage A:** `STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED=true` with fallback **OFF**, protected journey list, observation window, and rollback approver.  
3. After Stage A evidence: **Stage B:** `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED=true`.  
4. Whether **any** production statutory mints and **live WhiteBooks** calls are permitted during journey execution (distinct from flag enable).  
5. Confirmation that receipt allowlist remains **unchanged** unless a separate publication task is authorized.

Until then, production remains **v4.1.102** with both GST flags **OFF**.