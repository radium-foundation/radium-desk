# Work Queue created_at timezone fix — RadiumDesk-P-07-09-85

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-85`  
**Fixes:** P-07-09-84 production hide of six review orders after `9664345d`.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-84**. This ticket: **P-07-09-85**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Root cause

`orders.created_at` is a naive DATETIME persisted in `APP_TIMEZONE` (`Asia/Kolkata`). **VERIFIED** on production: `RDE318477` raw `2026-09-08 13:20:10`, Eloquent tz `Asia/Kolkata`.

`9664345d` converted the IST window to UTC, then bound those clocks to SQL:

```
now IST 17:17 → toUtc 11:47 → WHERE created_at <= '2026-09-08 11:47:00'
```

Afternoon IST `created_at` values (`13:20`–`16:24`) were excluded. The six orders were still review-shaped and had no fulfilment. **VERIFIED**.

The intended window is `2026-09-05 00:00:00 Asia/Kolkata` → current IST. The defect was the comparison, not the stored timestamps.

---

## Fix

`HardwareFulfilmentEligibility::createdAtSqlBound()` formats any instant as `Y-m-d H:i:s` in `Asia/Kolkata`.

Work Queue no longer calls `timezone('UTC')` before `workCandidateOrders` / `excludedFromWorkQueue`. Those queries bind the IST wall-clock strings.

No order rows rewritten. No eligibility-list change. No batch/automation.

---

## Tests

IST cutoff exact / immediately before / immediately after; Sept 4 18:45 IST stays out (would have been in under UTC start binding); afternoon-today in; future-today out; UTC Carbon persisted through Eloquent (stored as IST) in; six production-like IDs as fixtures only; RDE318421 fulfilment once; frozen/HOLD/blocked in; RIN out.

Focused 15 passed. Related hardware fulfilment suites passed. Pint clean.

---

## Safety

No fulfilment / serial / invoice / shipment / AWB / pickup / manifest writes. RDE318421 not rewritten. Shiprocket config not changed.

---

## Production overlay

**Mechanism:** named-file rsync (no `--delete`) from `git archive 1f8f6e33`. Not `./tools/desk deploy`. No migrate. No Vite. No `.env`.

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-85-20260908T115312Z`

Replaced 3 files. Production SHA-256 **MATCH** `1f8f6e33`. Then `optimize:clear` + `optimize`.

Post-overlay (user 2 GET, no browser): qualifying **13**, awaiting fulfilment **12**, blocked **9**, excluded **21**. All six afternoon review IDs visible. RDE318421 one table row, still `awb_assigned` / `1568724940` / `284931178067631`. `create/adhoc` log **0**. `/up` 200.

Rollback: restore the 3 backed-up files, then `optimize:clear` + `optimize`. Not required.
