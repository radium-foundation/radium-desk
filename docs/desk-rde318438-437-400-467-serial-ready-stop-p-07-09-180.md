# STOP serial-ready preparation — RDE318438 / RDE318437 / RDE318400 / RDE318467 — RadiumDesk-P-07-09-180

**Date:** 2026-09-10  
**Prompt ID:** `RadiumDesk-P-07-09-180`  
**Mode:** STOP → REPORT. Production SELECT + `desk:fulfil-hardware --dry-run` only. No writes.

Last used ledger ID: **P-07-09-179**. This ticket: **P-07-09-180**.

---

## Pre-change verification

| Item | Value |
|---|---|
| Located from | `/Users/ravi/RadiumWebsites/` |
| Repository | `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation` (git top-level; remote `git@github.com:radium-foundation/radium-desk.git`) |
| Branch | `feat/irn-foundation-phase-a` tracking `origin/feat/irn-foundation-phase-a` |
| HEAD SHA | `d95cd52a19ea5077e28946cb48f7eb54372749b0` |
| Worktree | Dirty (IRN foundation + PDF/env work already present). This ticket did not add application code. |
| Production | KVM `deskvps` / `srv1910783` `/var/www/radium-desk` DB `radium_desk` |
| Ledger | `docs/cursor-prompt-ledger.md` |
| Next unused ID at start | **P-07-09-180** (P-07-09-179 already recorded) |
| Inspect IST | 2026-09-10 12:43:42 |

Sibling worktrees exist (`radium-desk-pos-release`, `radium-desk`, …). This prompt targeted **irn-foundation**. Production was inspected via the live app, not inferred from order numbers.

---

## Why STOP

Serial entry in Desk requires an existing Hardware Fulfilment in `ready_for_fulfilment`, a physical line, Owner SKU map, and `assertCanAllocateSerials()`. Isolated ingest/READY still refuse HOLD, blocked-until-authorized, missing Commerce, and invented Box payloads.

None of the four orders currently pass that gate. Preparing them would require at least one of:

- fabricating a Box handoff JSON (forbidden),
- removing hardcoded HOLD / `BLOCKED_UNTIL_AUTHORIZED` constants (code; stop-before-code),
- treating Cashfree-only amounts as full Box order value for 437/467 (ambiguous payment).

No database mutation, serial assignment, ingest, READY, invoice, shipment, or application code change was performed.

---

## Order RDE318438

**Before state (production):**

- Support `orders.id=51546`, status `active`, Cashfree-verified NET_BANKING **₹3799.00**, not serial/transaction locked. Product name empty.
- Commerce: **0**. Hardware fulfilment: **0**. Ingest attempts: **0**. Recovered-fulfilment auth: **0**.
- Incident 52594 `awaiting_product_details`.
- Hardcoded `HOLD_SOURCE_IDS` includes this id. Classifier: `hold` → Dashboard **View**, blocker `Owner HOLD / recovery authorization required`.
- Dry-run ingest: `Owner-HOLD hardware orders cannot use the isolated fulfilment path.`

**Exact serial-entry blocker:** Owner HOLD plus no Commerce / no HF. Recovered-Commerce authorization is frozen-only and **refuses HOLD**. Isolated ingest cannot invent a Box payload.

**Changes made:** **NO — Not performed.**  
**Ready for serial addition:** **NO**  
**Serial assigned:** **NO**  
**Validation:** Dry-run fail-closed. No writes.

---

## Order RDE318437

**Before state (production):**

- Support `orders.id=51531`, status `active`, Cashfree-verified UPI **₹2000.00**, not serial/transaction locked. Product name empty.
- Commerce: **0**. Hardware fulfilment: **0**. Ingest attempts: **0**.
- Incident 52579 `awaiting_product_details`.
- Classifier: `awaiting_handoff` → **View**, blocker `Awaiting handoff. Box still holds the product payload; Desk has no Commerce line yet.`
- Dry-run ingest: `Isolated ingest requires --payload with exactly one verified handoff JSON object. Desk does not discover Box handoffs.`

**Exact serial-entry blocker:** No Commerce/HF. Prior tickets (P-07-09-98/99) recorded Box total ₹2499 with ₹499 wallet vs Desk/Cashfree ₹2000. This prompt did not re-read Box. Payment vs full order value remains **ambiguous**. Split-tender ingest was never delivered. Desk must not invent the missing ₹499 wallet tender or a Box payload.

**Changes made:** **NO — Not performed.**  
**Ready for serial addition:** **NO**  
**Serial assigned:** **NO**  
**Validation:** Dry-run fail-closed. No writes.

---

## Order RDE318400

**Before state (production):**

- Support `orders.id=51363`, Cashfree-verified CREDIT_CARD **₹5098.00**.
- Commerce **CO-000740** / id 740, `validated`, payment `paid` cashfree reference `6847824615`, order_value **5098.00**, taxable 4320.34, tax 777.66, wallet tender **null**, invoice id **null**.
- Line 1548: model **946**, qty **2**, description Mantra MFS 100/110 L1, line_total **5098.00**, `physical_merchandise`, Owner map product **28** `RBMFS110L1`, `is_serialized=true`.
- HF **14** `ingested` (2026-09-09 00:01:43), serials **0**, invoice **null**, shipment/AWB **none**. Event 25 `→ ingested`.
- Ingest attempt 751 `accepted` HTTP 201.
- Stock `RBMFS110L1`: available 895+1, reserved **0** (reservation is expected at allocate, not at ingest).
- Hardcoded `BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS`. Classifier: **View**, `Blocked until authorized. Do not ingest or ship.`
- `assertCanAllocateSerials`: `Serial allocation requires READY_FOR_FULFILMENT. Payment success is not enough.`
- Dry-run `--step=ready`: `This source id is not authorized for isolated fulfilment yet.`

**Minimum legitimate prep (not done):** owner-authorize by removing `RDE318400` from `BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS` (application code + tests), deploy that gate, then existing `desk:fulfil-hardware RDE318400 --step=ready` (no serials). Recovered-auth table **cannot** authorize this source (blocked-until-authorized is excluded). Bypassing via SQL READY would fabricate fulfilment state.

**Changes made:** **NO — Not performed.** (code stop; no READY)  
**Ready for serial addition:** **NO**  
**Serial assigned:** **NO**  
**Validation:** Dry-run fail-closed. HF14 `updated_at` unchanged. No writes.

---

## Order RDE318467

**Before state (production):**

- Support `orders.id=51694`, status `active`, Cashfree-verified UPI **₹1900.00**, not serial/transaction locked. Product name empty.
- Commerce: **0**. Hardware fulfilment: **0**. Ingest attempts: **0**.
- Incident 52742 `awaiting_product_details`.
- Classifier: `awaiting_handoff` → **View**, same missing-Commerce blocker as 437.
- Dry-run ingest: requires verified `--payload`; Desk does not discover Box handoffs.
- P-07-09-99 noted Desk/Cashfree **1900** as a possible same-class wallet remainder; Box total was **UNKNOWN** then and was **not** re-verified here. Payment vs full Box value is **ambiguous**.

**Changes made:** **NO — Not performed.**  
**Ready for serial addition:** **NO**  
**Serial assigned:** **NO**  
**Validation:** Dry-run fail-closed. No writes.

---

## Database tables/records changed

**NO — Not performed.**

## Backup

**NO — Not performed.** No mutation; production backup procedure not invoked.

## Tests

**NO — Not performed** (no code/data change). Live dry-run of `desk:fulfil-hardware` for each id: all four EXIT 1.

## Git / deploy

| Action | Result |
|---|---|
| Committed | **NO — Not performed.** |
| Commit SHA | **NO — Not performed.** |
| Pushed | **NO — Not performed.** |
| Remote | `origin` `git@github.com:radium-foundation/radium-desk.git` (no push) |
| Deployed | **NO — Not performed.** |
| Release/tag | **NO — Not performed.** |
| Production verification | Read-only inspect + dry-run. Counts: HF still **43**. Target serials still **0**. |

## Rollback status

**NO — Not required.** No writes.

## Remaining risks/blockers

1. **RDE318438** stays Owner-HOLD until a later prompt both lifts HOLD in application constants **and** supplies a verified Box commerce handoff (or recovered Commerce, which HOLD currently forbids).
2. **RDE318437** needs a verified split-tender Box payload (Cashfree ₹2000 + wallet remainder matching Box total). Do not ingest Cashfree-only ₹2000 as the full hardware sale.
3. **RDE318400** is the only order already ingested with matching ₹5098 / qty 2 / serialised SKU map. Next authorized prompt can change the blocked-until-authorized constant, then isolated `--step=ready` only. Do not allocate serials in that step unless explicitly authorized.
4. **RDE318467** needs a verified Box handoff; confirm Box paid total vs Desk ₹1900 before ingest.
5. This worktree is dirty with unrelated IRN work; do not mix a HOLD/authorization code change into that uncommitted set without an explicit code-authorized prompt.

Unrelated orders: **untouched** (no writes). Audit/history: **intact**. Serial numbers: **none assigned**.
