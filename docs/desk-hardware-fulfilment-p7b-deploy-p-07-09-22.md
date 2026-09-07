# P7B deploy tested hardware fulfilment foundation

**Project:** Radium Desk  
**Prompt:** **RadiumDesk-P-07-09-22**  
**Date:** 2026-09-07  
**Type:** Production overlay of P1–P6. Flags remain off. No ingest enable. No seven-order processing.  
**Verdict:** **PASS** — surgical overlay + four hardware migrations. Not a tagged `desk deploy`.

Classification: **VERIFIED** / **OWNER-LOCKED** / **INFERRED** / **UNKNOWN**.

---

## 0. Why not full `desk deploy`

Official `./tools/desk deploy` requires a clean tree, HEAD exactly equal to latest semver tag, and then runs `migrate --force` for **all** pending migrations.

| Gate | Finding | Class |
|------|---------|-------|
| Latest tag | `v4.0.67` on `dfc81936` | VERIFIED |
| Local HEAD before this ticket | `51735478` (P6), not tagged | VERIFIED |
| origin/main | `e4c3aec3` | VERIFIED |
| Production `release.json` | version 4.0.67 / build `5d14a582` / 2026-09-05 | VERIFIED |
| Unrelated pending migrations | POS UPI `2026_09_04_220000/220100/220200` still **Pending** | VERIFIED |

P7B forbids running non-P1–P6 migrations. Creating `v4.0.68` was not authorized. Overlay of `e4c3aec3..51735478` application files + path-scoped hardware migrations was the safe existing production path (same class as P-07-09-07 GST overlay).

---

## 1. Local validation (before overlay)

- Hardware + hasher: 95 passed
- Statutory GST + POS: 74 passed
- `php -l` on P1–P6 PHP: PASS
- Pint dirty: PASS

---

## 2. Production overlay

Maintenance mode, then rsync of 75 application/config/migration/view/route files from `51735478`. `.env` not copied. Tests/docs not copied.

Pre-migrate dumps (private disk):

- `storage/app/private/p7b-pre-schema-20260907T091254Z.sql.gz`
- `storage/app/private/p7b-pre-data-20260907T091254Z.sql.gz`

Migrations run **only** via `--path`:

| Migration | Result |
|-----------|--------|
| P1 `2026_09_07_125200_…` | DONE |
| P2 `2026_09_07_130400_…` | first attempt FAIL (MariaDB 64-char FK/index names) |
| P2 after short names | DONE |
| P4 `2026_09_07_140000_…` | DONE |
| P5 `2026_09_07_150000_…` | DONE |

UPI migrations remain Pending. **VERIFIED.**

P2 required a production-safe identifier rename (`hw_pay_ev_*`). Incomplete evidence table was dropped before retry. `paid_recognized_at` from the first attempt was kept (migration is now idempotent for that column).

`RolePermissionSeeder` ran so `hardware.fulfilment.operate` exists. It does not process orders.

Caches rebuilt. App returned to live. `/up` 200, `/login` 200, unauthenticated ingest 401.

---

## 3. Post-deploy verification

| Check | Before | After | Class |
|-------|--------|-------|-------|
| `hardware_fulfilments` | NO | YES, 0 rows | VERIFIED |
| `channel_sku_maps` | NO | YES, **0 rows** | VERIFIED |
| `shipments` | NO | YES, 0 rows | VERIFIED |
| Statutory issued / total / allocations | 141 / 141 / 141 | 141 / 141 / 141 | VERIFIED |
| `alloc_max_seq` | 130 | 130 | VERIFIED |
| `commerce_orders` | 231 | 231 | VERIFIED |
| `radiumbox_com` commerce | 0 | 0 | VERIFIED |
| Frozen support orders | 7 active | same ids/status | VERIFIED |
| Frozen commerce / statutory | 0 / 0 | 0 / 0 | VERIFIED |
| Shiprocket bind | n/a | `NullShiprocketGateway` | VERIFIED |
| Box callback bind | n/a | `NullBoxFulfilmentCallbackGateway` | VERIFIED |
| `correlate_cashfree` | n/a | false | VERIFIED |
| callback enabled / url / secret | KEY_ABSENT | false / EMPTY / EMPTY | VERIFIED |
| `shipping.enabled` / pickups | n/a | false / EMPTY | VERIFIED |
| `auto_issue_invoice` / `worker_may_mint` | false | false | VERIFIED |
| Box ingest secret | KEY_ABSENT | KEY_ABSENT | VERIFIED |

Production `ChannelIngestService` SHA-256 `d6b538f7…` matches this worktree. Production git SHA remains **UNKNOWN** (no `.git` on deploy path).

---

## 4. Not performed

Push, tag, full `desk deploy`, Box deploy, ingest enable, secret creation, SKU/pickup rows, Shiprocket HTTP, callback HTTP, Cashfree replay, seven-order processing, `radiumbox_prod` access, hardware invoice/serial/shipment/AWB.
