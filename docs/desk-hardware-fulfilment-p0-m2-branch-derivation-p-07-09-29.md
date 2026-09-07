# P0-M2 derive hardware fulfilment branch from selected physical serials

**Project:** Radium Desk  
**Prompt:** **RadiumDesk-P-07-09-29**  
**Date:** 2026-09-07  
**Type:** Implementation + local validation. No production write. No deploy.  
**Prior live ledger row:** P-07-09-27 (P0-M1 maps). P-07-09-28 was a read-only pickup discovery chat and is not reused.

Classification: **VERIFIED** / **OWNER-LOCKED** / **UNKNOWN**.

---

## 0. Repository verification (before modify)

| Item | Value | Class |
|------|-------|-------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Branch | `main` | VERIFIED |
| Before SHA | `d4be09e4266547a4da900f14e7a2eb6967d40cdc` | VERIFIED |
| `inventory_serials.branch_id` | Authoritative physical stock location | VERIFIED |
| Ingest writer | Creates fulfilment with `fulfilment_branch_id` null | VERIFIED |
| Customer state | Never an input to branch derivation | OWNER-LOCKED |

---

## 1. Branch derivation

`HardwareSerialAllocationService::allocate()`:

1. Lock the fulfilment row.
2. Normalize the selected serial numbers (existing per-line qty contract).
3. Lock each selected `inventory_serials` row (`lockForUpdate`, sorted).
4. Verify product map, availability, not sold, not allocated elsewhere, active supported stock branch.
5. Require exactly one physical `branch_id` across the selection.
6. If all `DELHI-RETAIL` → write `hardware_fulfilments.fulfilment_branch_id` to that branch.
7. If all `MUMBAI` → write `MUMBAI`.
8. If mixed → reject the entire transaction; do not allocate; do not write a branch.
9. Optional UI `claimed_branch` must match the locked serial branch; mismatch is rejected.
10. If a fulfilment already has a stored branch, selected serials must match it.
11. Only then run the existing `lockAvailableSerialsForSale` / mark-sold / `SERIALS_ALLOCATED` path.

Customer billing state, shipping state, GST state, buyer state, order state, GSTIN, and product are not consulted.

Supported stock branches are only `DELHI-RETAIL` and `MUMBAI`. Other codes fail closed.

---

## 2. Serial picker

Unset fulfilments can search available serials across supported stock branches. An optional `branch` query may narrow results. P-07-09-40 allocation UI does not send a branch override; fulfilment branch is written from `inventory_serials.branch_id`.

The filter is not trusted. Requirements also show per-branch available counts.

JSON validation errors for this search route render as JSON (`bootstrap/app.php` `shouldRenderJsonWhen`) so the picker can display the domain error instead of a 302 HTML redirect.

---

## 3. Downstream gates (unchanged)

- Hardware invoice still requires a fulfilment branch. Customer state cannot substitute.
- Shipment still requires fulfilment branch + pickup from `HardwarePickupResolver`.
- Pickup nicknames remain **UNKNOWN**. This prompt did not set `SHIPROCKET_PICKUP_*` or historical `RADDELHI` / `RADIUMUM`.

---

## 4. Concurrency

SQLite tests prove sequential duplicate allocation is rejected and mixed-branch transactions roll back.

**UNKNOWN:** true two-connection InnoDB overlapping writers. `phpunit.xml` forces sqlite `:memory:`.

---

## 5. Non-goals / not performed

No production serial allocation. No invoice issuance. No shipment create. No SKU map change. No frozen RDE* processing. No Shiprocket credential/nickname change. No ingest/callback/auto-mint enable. No push. No deploy.

---

## 6. Remaining blockers

| Item | Status |
|------|--------|
| Shiprocket pickup nicknames | UNKNOWN — Owner must supply P0-M2 nicknames |
| Live shipping | OFF |
| Box ingest / callback / hardware auto-mint | OFF |
| Seven frozen RDE* | Untouched; still blocked in code |
| Unpushed P1–P6 + this commit | Local `main` only |
