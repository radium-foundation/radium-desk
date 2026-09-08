# Awaiting Fulfilment review cutoff timezone fix — RadiumDesk-P-07-09-86

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-86`  
**Follows:** P-07-09-85 Work Queue IST window (HEAD `7a9018c0` before this ticket).

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-85**. This ticket: **P-07-09-86**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Independent verification (before the edit)

The Cursor note that Awaiting still used `cutoffInstant()->utc()` was treated as a hypothesis, not a copy-paste defect.

| Check | Result | Class |
|-------|--------|-------|
| Timestamp field | `orders.created_at` on the Awaiting `rdeWithoutFulfilment()` query | VERIFIED |
| Column type | naive `DATETIME` | VERIFIED (same column as P-07-09-85) |
| Stored clock | application timezone `Asia/Kolkata` | VERIFIED on production in P-07-09-85: `RDE318477` raw `2026-09-08 13:20:10`, Eloquent tz `Asia/Kolkata` |
| Binding | Laravel `Connection::prepareBindings` formats a `DateTimeInterface` as `Y-m-d H:i:s` in **that object's** timezone; it does not convert to `APP_TIMEZONE` | VERIFIED |
| `cutoffInstant()` | `2026-09-05 00:00:00` `Asia/Kolkata` | VERIFIED |
| Previous SQL bound | `cutoffInstant()->utc()` → `'2026-09-04 18:30:00'` | VERIFIED |
| Intended review start | `'2026-09-05 00:00:00'` IST | VERIFIED |
| Classifier `isOnOrAfterCutoff()` | already compares in IST | VERIFIED — PHP classification was already correct; only the SQL filter was wrong |
| Twilight window `2026-09-04 18:30:00`–`23:59:59` IST | 0 production orders | VERIFIED — the start bound was too early (review too inclusive / historical too exclusive). No current victims. |
| Upper `now` bound | Awaiting review has none | VERIFIED — afternoon-today rows were not hidden by this filter |

This is the same **class** of UTC-vs-naive-DATETIME mismatch as P-07-09-85, but a different **boundary**: Work Queue hid current-day afternoon rows via the end bound; Awaiting would have admitted pre-cutoff IST twilight rows via the start bound.

---

## Fix

`applyFilter()` review (default) and historical now bind:

```
HardwareFulfilmentEligibility::createdAtSqlBound(HardwareFulfilmentEligibility::cutoffInstant())
```

which is `Y-m-d H:i:s` in `Asia/Kolkata` (`2026-09-05 00:00:00`).

Not changed:

- Review eligibility rules (Cashfree paid, empty serial, empty transaction, frozen/HOLD/blocked exclusion, RIN exclusion).
- Work Queue / `workCandidateOrders` / `excludedFromWorkQueue` (already IST-bound in P-07-09-85).
- Stored timestamps.
- Read-only Awaiting behaviour (no create-all, no fulfilment mint).
- Open Fulfilments vs Work Queue vs Awaiting distinction.

---

## Tests

`test_review_cutoff_uses_ist_wall_clock_against_created_at` freezes now at `2026-09-08 17:17` IST.

| Fixture | `created_at` | Review | Historical |
|---------|--------------|--------|------------|
| RDE961040 | `2026-09-05 00:00:00` | in | out |
| RDE961041 | `2026-09-04 23:59:59` | out | in |
| RDE961042 | `2026-09-05 00:00:01` | in | out |
| RDE961043 | `2026-09-04 18:45:00` | out | in (would have been review under UTC bind) |
| RDE961044 | `2026-09-08 16:30:00` | in | out |
| RDE961045 | UTC cutoff instant formatted through `createdAtSqlBound` → `2026-09-05 00:00:00` | in | — |
| RDE961046 | `2026-09-01 10:00:00` | out | in |
| Frozen / `RDE255714` HOLD / blocked | in-window paid | out | — |
| RIN961047 | paid today | out | — |
| RDE318421 | existing fulfilment | absent; HF count stays 1 | absent |

Saving a UTC Carbon through Eloquent does **not** rewrite the clock to IST. RDE961045 therefore stores the IST wall-clock string from `createdAtSqlBound()`, not a raw UTC Carbon.

Focused awaiting + Work Queue + classifier + `createdAtSqlBound` unit: 16 passed. Related hardware fulfilment / serial / invoice / shipment / courier / navigation / operational: 168 passed. Pint: import order on the new test.

---

## Safety

No fulfilment / serial / invoice / shipment / AWB / pickup / manifest writes. RDE318421 not rewritten. Shiprocket config not changed. Global/batch automation remains OFF.

---

## Production overlay

**Mechanism:** named-file rsync (no `--delete`) from `git archive c4478cf7`. Not `./tools/desk deploy`. No migrate. No Vite. No `.env`.

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-86-20260908T120233Z`

Replaced 1 file: `app/Services/HardwareFulfilment/HardwareAwaitingFulfilmentQueue.php`. Production SHA-256 **MATCH** `c4478cf7` (`99095a0c…1037fec`). Then `optimize:clear` + `optimize`.

`createdAtSqlBound()` was already on production from P-07-09-85.

Post-overlay (user 2 GET via HTTP kernel, no browser):

| Check | Result |
|-------|--------|
| `/inventory/hardware-fulfilments` | 200; default is Work Queue |
| Awaiting tab | 200; heading present |
| Review candidates | **12** — all `created_at` ≥ `2026-09-07 12:22:57` IST |
| Twilight `2026-09-04 18:30`–`23:59:59` IST | 0 orders |
| Frozen / HOLD / blocked / RIN in review | none |
| RDE318421 in Awaiting / Historical | absent |
| Create fulfilment button | absent |
| HF total | 1 |
| RDE318421 | `1` / `awb_assigned` / `1568724940` / Delhivery_Surface/`15084` / AWB `284931178067631` / `updated_at` still `2026-09-08 16:55:54` |
| `create/adhoc` log | **0** |
| `/up` | 200; unauth list 302 `/login` |

Review IDs visible: RDE318490, RDE318489, RDE318486, RDE318487, RDE318482, RDE318477, RDE318469, RDE318467, RDE318435, RDE318437, RDE318434, RDE318401.

Rollback: restore the backed-up file, then `optimize:clear` + `optimize`. Not required.
