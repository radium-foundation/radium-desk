# P-20-17 — Controlled production GST Stage A activation

| Field | Value |
| --- | --- |
| Task | **P-20-17** |
| Cursor ledger | `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef` |
| Desk ledger row | **RadiumDesk-P-20-17** |
| Risk | **HIGH** |
| Start (IST) | **2026-11-20 10:01:47** |
| End (IST) | **2026-11-20 10:04:30** (approx.) |
| Duration | **~3 min** (+ provider call **~565 ms**) |

## Verdict

**PASSED** (Stage A activation + non-mint journeys) — **BLOCKED** for Stage B until mint-dependent gates and observation window complete.

**Stage A remains ENABLED** (`verification=true`, `fallback=false`) per Owner authorization. **Rollback not executed** — no safety stop condition met (no false invalidity, no auto B2C, no fallback mis-flag).

---

## Pre-activation identity (VERIFIED)

| Item | Value |
| --- | --- |
| Release | **v4.1.102** / **`339e3dc99`** |
| `DB_DATABASE` | **`radium_desk`** |
| Recovery backup | **`20261120T041822Z`**, SHA **match** (`1f21c44b…`) |
| POST-RIP `…0843…339e3dc` | **PASS** |
| Repo `main` | **`893bf0a3a`** (not deployed; no drift blocker) |
| Initial verification | **OFF** |
| Initial fallback | **OFF** |
| Receipt allowlist | **8** IDs |

### Baseline counts (pre-activation)

| Metric | Value |
| --- | ---: |
| `commerce_order_buyer_gst_b2c_conversions` | **0** |
| `commerce_order_buyer_gst_verifications` | **0** |
| `statutory_invoices` max `id` | **23270** |

---

## Activation

| Step | Result |
| --- | --- |
| `.env` `STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED=true` | **Applied** |
| `.env` `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED=false` | **Applied** |
| `config:cache` | **OK** |
| `radium-desk-queue-worker` restart | **OK** |
| Pre-change `.env` copy | **`.env.p20-17-pre-stage-a-bak`** on host |
| Activation window (UTC) | **04:32:18 – 04:32:27** (~**10:02:18 – 10:02:27 IST**) |

### Final runtime config (VERIFIED)

| Flag | Value |
| --- | --- |
| `verification` | **true** |
| `b2c_fallback` | **false** |
| WhiteBooks gateway `isConfigured()` | **true** |
| Receipt allowlist | **8** |

---

## Protected journeys (no operator-initiated mint)

| Matrix | Journey | Executed | Result |
| --- | --- | --- | --- |
| **#8** | State mismatch live lookup — **RBP2295** / `commerce_order_id` **23661** | **YES** | `requires_provider=true`; **1** WhiteBooks call **~565 ms**; status **`review_required`**; reason **`inactive_registration`**; reg **`Suspended`**; mint gate **`Buyer GSTIN requires GST review.`**; **0** conversion rows |
| **#1** | State-match sample — `commerce_order_id` **24217** / **RD36845** | **YES** | `requires_provider=false`; mint gate blockers **[]** (mint **not** invoked) |
| **#4** | Missing GSTIN sample — `commerce_order_id` **24233** / **RB1921** | **YES** | `requires_provider=false`; **0** conversion rows; mint gate **[]** (mint **not** invoked) |
| **#2–3, #5–7, #9–10** | Mint / conversion / idempotency / receipt expansion | **NOT RUN** | Excluded by **no-mint** instruction or requires Stage B |
| **#5** provider timeout path | Forced 5xx / timeout | **NOT RUN** | No fault injection authorized |

**Provider correlation:** P-19-60 live lookup on same order — outcome class **consistent** (`inactive_registration` / Suspended).

**Operator minting:** **NO — Not performed.**

---

## Post-activation counts

| Metric | Value |
| --- | ---: |
| `commerce_order_buyer_gst_b2c_conversions` | **0** |
| `commerce_order_buyer_gst_verifications` | **1** (`review_required`) |
| `statutory_invoices` max `id` | **23271** |

### Background issuance note (VERIFIED, not operator-initiated)

During the activation window, `statutory_invoices.id` **23271** (`INV-6720760`, `source_id` **RB1921**, blank buyer GSTIN, `created_at` **10:02:39 IST**) was issued. Same `source_id` as matrix **#4** probe order **24233**. **P-20-17 did not call** `StatutoryInvoiceService::issue*`. Treat as **ongoing production auto-issuance**, not an authorized protected-journey mint test.

---

## Stage A gates

| Gate | Result | Evidence |
| --- | --- | --- |
| **A-P1** Config effective | **PASS** | Runtime `verification=true`, `b2c_fallback=false` |
| **A-P2** No auto B2C | **PASS** | Conversions **0** → **0** |
| **A-P3** State-match B2B mint | **BLOCKED** | Mint not authorized |
| **A-P4** Missing GSTIN mint | **BLOCKED** | No authorized mint assertion (background RB1921 invoice noted) |
| **A-P5** State-mismatch path | **PASS** | Verification row + provider outcome; hold via review; **0** conversions |
| **A-P6** Provider error → hold | **NOT RUN** | No timeout/5xx injection |
| **A-P7** IRN | **PASS** | No operator mint/IRN; no new linkage attributed to journeys |
| **A-P8** Receipt allowlist | **PASS** | **8** IDs unchanged |
| **A-P9** Idempotent mint | **BLOCKED** | Mint not authorized |
| **Idempotency (verification row)** | **PASS** | Second `verifyAndPersist` on **23661** → **1** row |

---

## Production mutations

| Item | Changed? |
| --- | --- |
| `radium_desk` schema | **NO** |
| `.env` GST flags | **YES** (Stage A authorized) |
| Statutory rows via operator | **NO** |
| Verification audit | **YES** — **1** row (order **23661**, authorized live lookup) |
| RIP checkpoints | **Unchanged** |
| Stage B fallback | **OFF** |

---

## Explicit non-actions

| Action | Status |
| --- | --- |
| Operator production mint | **NO — Not performed** |
| IRN generation (operator) | **NO — Not performed** |
| Receipt publication / allowlist change | **NO — Not performed** |
| Stage B | **NO — Not performed** |
| Deploy / schema migrate | **NO — Not performed** |

---

## Rollback readiness

**A-R1** (if required): set `STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED=false` (or remove), `config:cache`, restart `radium-desk-queue-worker`. Verification row **23661** retained.

Restore point: **`20261120T041822Z`** @ **`339e3dc99`**.

---

## Remaining risks & next Owner decision

1. **24–72 h observation** (P-20-15 A5) — **in progress**.
2. Mint-dependent gates **A-P3 / A-P4 / A-P9** need **separate mint authorization** or UAT evidence.
3. **Stage B** requires **separate Owner instruction**; remains **OFF**.
4. Monitor `commerce_order_buyer_gst_verifications` growth and WhiteBooks error logs.

**Next decision:** Continue Stage A observation, authorize targeted mint-only gate tests, or order **A-R1 rollback**.