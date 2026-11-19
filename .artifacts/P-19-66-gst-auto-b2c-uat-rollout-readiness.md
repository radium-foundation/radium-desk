# P-19-66 — GST automatic B2C UAT and rollout readiness

| Field | Value |
| --- | --- |
| ChatGPT ID | **P-19-66** |
| Cursor ledger ID | `bd0ac5bd-88f0-48e0-aacd-66eca9be9aef` |
| Desk ledger row | **RadiumDesk-P-19-66** |
| Risk | **HIGH** |
| Start (IST) | **2026-11-19 ~22:10** (session) |
| End (IST) | **2026-11-19 ~22:22** (session) |
| Duration | **~12 min** measured (verify + tests + docs) |

## Merge ancestry (VERIFIED)

| Prompt | Merge SHA | On `origin/main` |
| --- | --- | --- |
| P-19-63 | `35ed62843b55cc8c9a270bf19ede08cff3714d65` | YES |
| P-19-64 | `58e3ac0f47cbaa403905190ffabb3efa6f1fc5f4` | YES |
| P-19-65 | `dfe82ffcf17f2ad7a0cd360deba59fed319873aa` | YES |

## Production state (VERIFIED from prior artifacts — not re-mutated)

| Item | Value | Source |
| --- | --- | --- |
| Deployed release | **v4.1.101** / **`7edf904bb`** | P-19-56, P-19-57 |
| `main` ahead of prod | **YES** — includes P-19-64/65 through **`dfe82ffc`** | git fetch |
| `STATUTORY_BUYER_GSTIN_VERIFICATION_ENABLED` | **false** (default) | `config/statutory_invoices.php` |
| `STATUTORY_BUYER_GSTIN_B2C_FALLBACK_ENABLED` | **false** (default) | same |
| GST migrations on prod schema | **Pending** (`120000`+); tables absent per P-19-57 | P-19-57 audit |
| Invoice **23163** / **RBP2295** | **Not operated on** | task restriction |

## UAT environment (BLOCKED for live execution)

| Check | Result |
| --- | --- |
| Named isolated Desk UAT URL + Owner sign-off today | **UNKNOWN / not re-verified** |
| DB isolation proof (≠ `radium_desk` prod) | **NOT VERIFIED** this session |
| Safe flag-on mint on shared/staging host | **BLOCKED** — policy: no assumed isolation |
| WhiteBooks live/sandbox UAT calls | **NOT RUN** — Http::fake in tests only |

**Substitute validation:** controlled local synthetic UAT (PHPUnit + RefreshDatabase + provider fakes) at merge **`dfe82ffc`**.

## Test evidence (VERIFIED)

| Command | Exit | Duration | Result |
| --- | --- | --- | --- |
| `phpunit --filter 'CommerceOrderBuyerGstB2c\|CommerceOrderBuyerGstAuto\|CommerceOrderBuyerGstVerificationTest\|BuyerGstB2cConversionMysqlConcurrencyTest'` | **0** | **~131s** | **33/33 PASS**, 154 assertions |
| MariaDB concurrency (in above) | **0** | ~128s of total | **2/2 PASS** (`127.0.0.1:33118`, disposable DB) |
| CI on P-19-65 merge | — | rip **41s**, release-rip **1m11s** | **PASS**; statutory-cancellation-phpunit **NOT RUN** on PR #369 |
| Full PHPUnit | — | — | **NOT RUN** (policy) |
| Production deploy / UAT browser | — | — | **NOT RUN** |

Worktree: `/Users/ravi/RadiumWebsites/radium-desk/.worktrees/p19-65-gst-auto-b2c` @ **`dfe82ffc`** (fast-forward).

## Acceptance matrix

See **`docs/desk-p19-66-gst-auto-b2c-uat-acceptance-matrix.md`**.

**Summary:** Local synthetic lane **PASS** for automatic B2C, B2B protection, IRN skip, post-mint reject, concurrency. **Live isolated UAT BLOCKED.**

## Rollout readiness (assessment only — no execution)

### Required migrations (order)

| Order | Migration | Prod status (P-19-57) |
| --- | --- | --- |
| 1 | `2026_11_19_120000_create_commerce_order_buyer_gst_verifications_table` | **Pending** |
| 2 | `2026_11_19_130000_create_commerce_order_buyer_gst_b2c_conversions_table` | **NOT RUN** on prod (depends on 1) |
| 3 | `2026_11_19_140000_add_statutory_invoice_id_to_commerce_order_buyer_gst_b2c_conversions_table` | **NOT RUN** on prod |

**Risk if flags enabled before migrations:** verification/conversion persistence **fails** or flags appear ON while schema missing — **unsafe**.

**Reversibility:** Dropping (2) loses conversion audit; dropping (1) destroys verification evidence and breaks (2). Forward-only on prod unless Owner-authorized rollback plan.

### Flag sequencing (Owner gates)

| Stage | Action |
| --- | --- |
| A | Deploy code ≥ **`dfe82ffc`** with **both flags OFF** |
| B | Apply migrations 1→3 in maintenance window + **fresh encrypted backup** + snapshot gate |
| C | UAT isolated: enable **verification** only; exercise B2B verified path |
| D | UAT isolated: enable **b2c_fallback**; exercise auto B2C matrix |
| E | Production: CA matrix approvals + written evidence |
| F | Production: enable flags per runbook — **separate** Owner authorization each |

### Backup / recovery

| Item | Status |
| --- | --- |
| Fresh post-deploy backup before migration | **REQUIRED** — not created in this task |
| Reuse pre–v4.1.101 backup for GST schema | **INSUFFICIENT** (P-19-58) |
| Rollback | Redeploy prior tag + **keep flags OFF**; migration rollback **not** automatic |

### CA / Finance matrix

**All rows PENDING** (`docs/desk-p19-62-gst-b2c-fallback-ca-approval-matrix.md`). Engineering note: matrix text still says “manual approval” for several rows; **runtime automatic path (P-19-65) does not require manual approval** when fallback flag is on. **CA sign-off must be updated** to reflect automatic conversion — not reverted in code.

### Email-log confirmation

**GAP** — `docs/desk-p19-64-gst-b2c-email-log-gap.md`. Separate gated follow-up.

### Post-deploy smoke (when authorized)

- Active GSTIN → B2B invoice with recipient GSTIN.
- Suspended GSTIN → auto conversion row (`authorized_by` null), B2C invoice blank GSTIN, masked footer.
- Provider error fixture → technical remark, no IRN queue.
- Post-mint order → conversion rejected.

## Changes in P-19-66

| | |
| --- | --- |
| **Preserved** | Production unchanged; prod flags OFF; automatic B2C code on `main`. |
| **Extended** | UAT acceptance matrix + rollout readiness artifact. |
| **Changed** | None in application code. |
| **Removed** | None |

## Production operations — explicit NO

Production migration · backup · flag change · deploy · invoice ops · WhiteBooks production · customer email · financial mutations · modification of **23163** / **RBP2295** — **NO — Not performed**.

## Owner approvals still required

1. Isolated UAT environment proof (or authorize disposable KVM UAT refresh per historical `radium-desk-uat` pattern).
2. Migration window + encrypted backup + snapshot gate.
3. Deploy **`main`** > **v4.1.101** to production (code containing P-19-65).
4. CA matrix rows → **APPROVED** (wording update for automatic path).
5. Staged flag enablement (verification, then fallback).
6. Email-log follow-up if required for audit policy.

**Not production-ready** on evidence of local tests alone. **Not rollout-ready** until migrations, backup, isolated UAT pass, and CA matrix complete.