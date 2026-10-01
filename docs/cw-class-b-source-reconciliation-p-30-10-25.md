# Class-B Source Reconciliation — 14 Refunds

**Prompt ID:** `RadiumDesk-P-30-10-25`  
**Date:** 2026-10-01  
**Mode:** Forensic read-only source reconciliation + empty batch preparation — **no financial execution**

**Owner approval:** `R-CW-REMAINING239-FIN-MIG-20261001-001`

---

## Executive summary

| Metric | Value |
|---|---:|
| Class-B input | **14 / ₹7,810** |
| DETERMINISTIC_SOURCE_FOUND | **0 / ₹0** |
| MULTIPLE_POSSIBLE_SOURCES | **0 / ₹0** |
| NO_AUTHORITATIVE_SOURCE | **14 / ₹7,810** |
| SOURCE_EXISTS_BUT_AMOUNT_MISMATCH | **0 / ₹0** |
| OTHER_BLOCKED | **0 / ₹0** |
| **Prepared batch** | **0 / ₹0** |
| **Manifest SHA-256** | `4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945` |

All 14 rows have **CWID + account link established**, but **no spoke `users_wallet` row** carries an authoritative `desk_refund_reference`, deterministic message link to the current refund reference/order, or non-colliding `execution_transaction_id`. Aggregate user spendable balance often equals the refund amount; that alone is **not** treated as provenance.

---

## Forensic classification (14 / ₹7,810)

| refund_id | amount | site | state | key finding |
|---:|---:|---|---|---|
| 44 | ₹597 | rdservice.in | NO_AUTHORITATIVE_SOURCE | Wallet 2223 credit ₹597; message cites **RD277882** not order **RD3459150**; no `desk_refund_reference` |
| 112 | ₹499 | rdservice.in | NO_AUTHORITATIVE_SOURCE | Wallet 2303; message cites different order **RD282927** |
| 117 | ₹499 | rdservice.in | NO_AUTHORITATIVE_SOURCE | Wallet 2312; message cites **RD289707** vs order **RD3469677** |
| 119 | ₹997 | radiumbox.com | NO_AUTHORITATIVE_SOURCE | Wallet 2321; spendable **₹0**; message cites **RD290410**; Box executor also not deployed |
| 122 | ₹499 | radiumbox.com | NO_AUTHORITATIVE_SOURCE | Wallet 2318; spendable **₹0**; message cites **RD289314** |
| 123 | ₹497 | rdservice.in | NO_AUTHORITATIVE_SOURCE | Wallet 2319; message cites **RD289341** |
| 132 | ₹497 | rdservice.in | NO_AUTHORITATIVE_SOURCE | Wallet 2332; message cites **RD292435** |
| 138 | ₹499 | rdservice.in | NO_AUTHORITATIVE_SOURCE | Wallet 2343; message cites **RD295395** |
| 178 | ₹499 | rdservice.in | NO_AUTHORITATIVE_SOURCE | Wallet 2395; spendable **₹0**; message cites **RD303305** |
| 180 | ₹499 | rdservice.in | NO_AUTHORITATIVE_SOURCE | Wallet 2403; message cites **RD305950** |
| 187 | ₹631 | rdservice.in | NO_AUTHORITATIVE_SOURCE | Wallet 2408; message cites **RD306738** |
| 199 | ₹499 | rdservice.in | NO_AUTHORITATIVE_SOURCE | Wallet 2420; message cites **RD306060** |
| 208 | ₹599 | rdservice.in | NO_AUTHORITATIVE_SOURCE | User spendable **₹1,698**; exact ₹599 credit wallet 2435 but message cites unrelated order; aggregate ≠ single-credit proof |
| 235 | ₹499 | rdservice.in | NO_AUTHORITATIVE_SOURCE | Wallet 2493; message cites **RD305760** |

### Pattern

Legacy/historical wallet credits use generic messages (`Amount Refunded for Order #RD…`) referencing **prior** orders, without `desk_refund_reference` backfill or `execution_transaction_id` on the Desk refund row. Exact credit amount + aggregate spendable match is **insufficient** per migration provenance rules.

---

## Prepared batch

**0 rows** — no refund passed deterministic source + destination + balance gates.

```bash
php artisan central-wallet:next-safe-batch-import --manifest=storage/app/private/cw-next-safe-batch-manifest-p30-10-25.json
php artisan central-wallet:next-safe-batch-dry-run --manifest=storage/app/private/cw-next-safe-batch-manifest-p30-10-25.json
```

---

## Refund 360 / RadiumBox executor blocker (separate assessment)

| Field | Value |
|---|---|
| refund_id | **360** |
| amount | **₹849** |
| desk_refund_reference | **REF-67363** |
| site | **radiumbox.com** |
| source_wallet_id | **2567** (authoritative via `desk_refund_reference`) |
| CWID | established |
| Lane | **LANE_A_SPOKE_DEBIT** (blocked) |

### What is missing

1. **Desk `CentralWalletServiceProvider`** wires `HttpWalletMigrationSpokeClient` only to **rdservice.in** (`order_lookup.spokes.rdservice_in`). No radiumbox.com spoke client binding exists; absent config → `NullWalletMigrationSpokeClient`.
2. **radiumbox.com** has **no** production routes/controllers for:
   - `POST /api/integrations/v1/wallet-migration-locks/acquire`
   - `POST /api/integrations/v1/wallet-migration-locks/release`
   - `POST /api/integrations/v1/wallet-migration-retirements`
   - `POST /api/integrations/v1/wallet-migration-status`
   - `POST /api/integrations/v1/wallet-migration-restorations`
3. **rdservice.in** already implements these (see `DeskWalletMigrationInfrastructureTest` + `routes/api.php`).

### Lane-1 architecture compatibility

**YES, in principle.** `RefundMigrationLane1Executor` is spoke-agnostic; it calls `WalletMigrationSpokeClient` with `source_application` from the journal row. Refund 360 already has deterministic Box source wallet **2567** / user **499465** / credit **₹849**. The blocker is **infrastructure**, not ledger design.

### Required next actions (radiumbox.com — out of scope for this prompt)

1. Port rdservice.in wallet-migration lock/retire/restore API surface to radiumbox.com.
2. Add integration token + Desk spoke config for `radiumbox.com` migration base URL/token/host header.
3. Extend Desk `CentralWalletServiceProvider` to resolve a Box `HttpWalletMigrationSpokeClient` when `source_application === 'radiumbox.com'`.
4. Feature tests mirroring `DeskWalletMigrationInfrastructureTest` on Box.
5. Surgical deploy Box API + Desk provider wiring; **then** re-run Ready-4 / remaining-239 preflight for refund 360 only.

**No radiumbox.com changes made in P-30-10-25.**

---

## Financial zero check

| Metric | Before | After |
|---|---|---|
| Ledger credits | 53 / ₹27,725 | **unchanged** |
| Reconciled journal | 53 | **unchanged** |
| Lane 4 executed | 0 | **unchanged** |
| Execution flags | OFF | **OFF** |

---

## Artifacts

| File | Purpose |
|---|---|
| `cw-class-b-source-reconciliation-p30-10-25.json` | Full per-refund forensic audit |
| `cw-class-b-source-reconciliation-p30-10-25.csv` | Human-readable export |
| `cw-class-b-source-reconciliation-p30-10-25.py` | Reconciliation script |
| `cw-next-safe-batch-manifest-p30-10-25.json` | Empty prepared batch |
