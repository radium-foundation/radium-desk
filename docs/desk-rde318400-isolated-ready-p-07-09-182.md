# Isolated READY — RDE318400 / CO-000740 / HF14 — RadiumDesk-P-07-09-182

**Date:** 2026-09-10  
**Prompt ID:** `RadiumDesk-P-07-09-182`  
**Mode:** Authorize verified source + isolated `--step=ready` only. No serials. No invoice. No ship.

Last used ledger ID at start of this ticket: **P-07-09-181** (WhiteBooks contract docs). This ticket: **P-07-09-182**. Overlay directory was named `p-07-09-181-*` before 181 was claimed; live overlay is that backup path.

Before SHA: `316d168b3736cb045eef7291c30f1c9d945b56bc`  
Branch: `feat/irn-foundation-phase-a`  
Repository: `/Users/ravi/RadiumWebsites/radium-desk-irn-foundation`

Production: KVM `deskvps` / `srv1910783` `/var/www/radium-desk` DB `radium_desk`. PHP `/usr/local/lsws/lsphp84/bin/php`.  
Deployment: named-file overlay of 2 PHP files. **Not** `./tools/desk deploy` (dirty IRN worktree).

---

## Gate

`HardwareFulfilmentEligibility::BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS` was `['RDE318400']`.  
`isBlockedUntilAuthorized()` is used by isolated `assertIsolatedTarget` / `markReady()`, recovered-auth, awaiting classifier, callbacks.

RDE318400 is the only previously blocked source. HOLD `RDE318438` unchanged. Frozen list unchanged.

Authorization: `AUTHORIZED_ISOLATED_SOURCE_IDS = ['RDE318400']`. The blocked list is now empty. `isBlockedUntilAuthorized('RDE318400')` returns false. Other IDs are still not on an allow-all path; they still need paid commerce, hardware lines, cutoff, and (if no HF) a verified Box payload. HOLD/frozen still refuse.

`HardwareAwaitingFulfilmentQueue` blocked-count treats an empty blocked list as 0 (no empty `whereIn`).

`markReady()` only transitions `ingested` → `ready_for_fulfilment`. It does not mint invoices or allocate serials.

---

## Overlay

Backup: `/var/www/radium-desk/storage/app/private/overlays/p-07-09-181-20260910T075053Z`

| File | Live SHA-256 after overlay |
|---|---|
| `HardwareFulfilmentEligibility.php` | `71e9a4d67c92a4fe29fc73e2c8e990d2679d5a97b00d234d012885d818de7604` |
| `HardwareAwaitingFulfilmentQueue.php` | `d28f778201c22ad8252e9d0f813669e089743d9135bb10eb982555ed7ab34538` |

`php -l` both files. `artisan optimize:clear` + `optimize`.

---

## Production READY

Dry-run `--step=ready`: `ok=true`, state `ingested`, commerce 740, no writes.  
Live: `desk:fulfil-hardware RDE318400 --step=ready` at 2026-09-10 13:21:12 IST.

Event 271: `ingested → ready_for_fulfilment`. Event 25 (`→ ingested`) preserved.

| Field | After |
|---|---|
| HF14 | `ready_for_fulfilment` |
| Serials | 0 |
| Invoice | none |
| Commerce CO-000740 | paid ₹5098.00; `updated_at` still 2026-09-09 00:01:43 |
| Support 51363 | payment_amount 5098.00 CREDIT_CARD |
| Qty | 2 |
| SKU | `RBMFS110L1` serialized |
| `assertCanAllocateSerials` | true |

HF count still 43. RDE318438/437/467 still commerce 0 / HF 0. Dry-run ingest still HOLD / missing payload.

---

## Tests

Focused (no Vite): IngestReady + IsolatedOneOrder + RecoveredAuthorization + OpenRecoveredHttp (excluding dashboard GET) + OperationalClassifier + AwaitingClassifier + P2Workflow: **passed**.

View tests that GET Blade (`WorkQueue`, serial allocation page, one OpenRecovered dashboard GET) fail locally with missing `public/build/manifest.json` — pre-existing on this IRN worktree, not this gate.

---

## Not performed

Serial assignment, invoice mint, Shiprocket, AWB, Box payload, SQL READY, `deskd`, IRN files, 437/438/467 mutation.
