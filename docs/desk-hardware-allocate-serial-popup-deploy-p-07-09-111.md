# Deploy multi-unit Allocate Serial popup — RadiumDesk-P-07-09-111

**Date:** 2026-09-09  
**Prompt ID:** `RadiumDesk-P-07-09-111`  
**Implementation:** `5d179e1196e5f467678eb8fbb66def8ddcafed43` (P-07-09-110)  
**Mechanism:** individual `install -m 644` from `git archive 5d179e11` plus local Vite `manifest.json` and `assets/app-99SOJ5nt.js`. Not `./tools/desk deploy`. Not a directory rsync onto `/var/www/radium-desk`. No `--delete`. No migrate. No `.env` change.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID before this ticket: **P-07-09-110**. This ticket: **P-07-09-111**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Why not full `desk deploy`

| Gate | Finding | Class |
|------|---------|-------|
| Local worktree | Dirty unrelated `ShiprocketCreateOrderRequest.php` + statutory docs + untracked investigation markdown | VERIFIED |
| Pending UPI migrations | `220000` / `220100` / `220200` still Pending | VERIFIED |
| Official deskd | Would rsync dirty tree and run unscoped `migrate --force` | VERIFIED |
| P-07-09-90 | Directory `rsync -a` from `mktemp` set web root to `700` and caused public HTTP 403 | VERIFIED |
| Official Vite rsync | `sync_kvm_public_build` uses `--delete` | VERIFIED |

---

## Pre-deploy

| Item | Value | Class |
|------|-------|--------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Branch | `main` | VERIFIED |
| Before SHA | `72da69c6c2362e21b35b571e955c0458f30c6961` | VERIFIED |
| Release SHA | `5d179e1196e5f467678eb8fbb66def8ddcafed43` | VERIFIED |
| Server | KVM `srv1910783` / `187.127.129.16` | VERIFIED |
| App path | `/var/www/radium-desk` | VERIFIED |
| PHP | `/usr/local/lsws/lsphp84/bin/php` | VERIFIED |
| Public URL | `https://desk.radiumbox.com` | VERIFIED |
| Web root before | `755 ravi:ravi` | VERIFIED |
| Prior Vite | login referenced `app-nLcHXNoc.js` (P-07-09-96) | VERIFIED |
| Replace-target hashes | 4/4 MATCH `9f423118` | VERIFIED |

`HardwareSerialAllocationService::allocate()` was **not** in the overlay set. Production hash `a6b3c582…` MATCH local `5d179e11`. File mtime remained `2026-09-08 14:10:33`.

---

## Overlay set

### Application files (4 from `git archive 5d179e11`)

- `app/Http/Controllers/Inventory/HardwareFulfilmentSerialController.php`
- `resources/js/hardware-action-dialog.js`
- `resources/views/inventory/hardware-fulfilments/fragments/action-allocate-serial.blade.php`
- `resources/views/inventory/hardware-fulfilments/fragments/action-dialog.blade.php`

Tests, docs, `.env`, migrations, `HardwareSerialAllocationService.php`, and dirty shipping/statutory files were **not** copied.

### Vite files

Installed individually into `/var/www/radium-desk/public/build/` without `--delete`:

- `manifest.json`
- `assets/app-99SOJ5nt.js`

Previous hashed `app-nLcHXNoc.js` was **kept**. All other current-manifest chunks already existed on production (HAVE). CSS remained `app-D4lQXdS3.css`. Dashboard remained `dashboard-BsgJcaDW.js`.

---

## Named-file overlay

| Item | Value |
|------|-------|
| Backup | `/var/www/radium-desk/storage/app/private/overlays/p-07-09-111-20260909T020402Z` |
| Timestamp | `20260909T020402Z` |
| Files backed up | 4 replaced app files + previous `public/build/manifest.json` + `rde318421-before.txt` |
| Install | individual `install -m 644` / `ravi:ravi` from `/tmp/hf-serial-popup-5d179e11-stage` |
| Hash verify | 4/4 app MATCH `5d179e11`; Vite MATCH local build |
| Web root after install | `755 ravi:ravi` |
| Cache | `optimize:clear` + `optimize` |
| Web root after cache | `755 ravi:ravi` |

`rsync -a` of a `mktemp` directory onto `/var/www/radium-desk`: **NO — Not performed.**  
Vite `--delete`: **NO — Not performed.**  
Migrate: **NO — Not performed.**

---

## Push

`git push origin HEAD` published `931b6b59..5d179e11` on `origin/main`. That includes 23 already-overlaid hardware commits plus this UI commit. Local HEAD MATCH `origin/main` = `5d179e11`.

---

## Production verification (GET / render only)

| Check | Result | Class |
|-------|--------|-------|
| `https://desk.radiumbox.com/up` | 200 | VERIFIED |
| `https://desk.radiumbox.com/login` | 200; HTML references `app-99SOJ5nt.js` + `app-D4lQXdS3.css`; old `app-nLcHXNoc.js` absent from HTML | VERIFIED |
| `/build/assets/app-99SOJ5nt.js` | 200; contains mixed-branch, duplicate, incomplete, and `Allocating…` strings | VERIFIED |
| Dashboard `?workspace=hardware` as user 2 | 200; new Vite hash; workspace present; next-action label remains **Allocate Serial** (24); **Allocate Serials** = 0 | VERIFIED |
| GET action-dialog fulfilment `1` | 200; dialog shell present; allocate fragment absent because HF 1 is `awb_assigned` | VERIFIED |
| READY_FOR_FULFILMENT rows | **0** (`awb_assigned=1`, `ingested=13`) | VERIFIED |
| RDE318421 | `hf.updated_at=2026-09-08 17:54:42`; AWB `284931178067631`; pickup `2026-09-08 18:35:50` unchanged | VERIFIED |

Qty 1 / Qty 2 / multi-product HTML was rendered from the **installed production Blade** with synthetic requirements (no DB write, no serial search POST, no allocate POST):

| Case | Result |
|------|--------|
| Qty 1 | `data-compact="1"`; `Serials allocated: 0 / 1`; `data-qty="1"`; submit `disabled`; compact footer omitted |
| Qty 2 | `data-compact="0"`; `Serials allocated: 0 / 2`; `data-qty="2"`; submit `disabled` |
| Two product lines | two `[data-hardware-serial-picker]` nodes; `data-item-id` 9001 and 9002; Product 1 / Product 2 |
| Hidden `serials[id][]` | absent until JS select (expected); production `app-99SOJ5nt.js` contains `serials[` |

Live click-select of inventory serials was **not** performed: there is no READY fulfilment to open.

---

## Dashboard terminology

Classifier / routing `nextAction` remains **Allocate Serial**. Changing it is not presentation-only (`=== 'Allocate Serial'` gates the action-dialog include). Popup title already becomes **Allocate Serials** when qty > 1 or there are multiple lines.

---

## Not performed

- Schema / migrate / UPI migrate: **NO — Not performed.**
- `.env` / secrets: **NO — Not performed.**
- Serial allocation POST: **NO — Not performed.**
- Invoice / shipment / AWB / label / pickup / manifest writes: **NO — Not performed.**
- Shiprocket HTTP: **NO — Not performed.**
- Outbox artisan invoke: **NO — Not performed.** (scheduled worker continued independently; this ticket did not start it)
- RDE318421 / frozen-order recovery: **NO — Not performed.**
- Full `deskd` / directory rsync onto web root / Vite `--delete`: **NO — Not performed.**
- Rollback: **NO — Not performed.**

---

## Rollback

Restore the 4 replaced app files and previous `public/build/manifest.json` from `p-07-09-111-20260909T020402Z`. Leave `app-99SOJ5nt.js` in place or unused (it was not present before; old HTML will point at kept `app-nLcHXNoc.js`). Then `optimize:clear` + `optimize`. Confirm `/var/www/radium-desk` stays `755`. Rollback was **not** required.
