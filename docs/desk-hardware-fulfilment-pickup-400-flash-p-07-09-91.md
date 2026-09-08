# Pickup 400 still flashed after P-07-09-88 — RadiumDesk-P-07-09-91

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-91`  
**Follows:** P-07-09-88 pickup reconcile; P-07-09-89/90 Operations overlay + web-root 755.

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-90**. This ticket: **P-07-09-91**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Production vs repo (before modify)

| Item | Value | Class |
|------|-------|--------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Branch | `main` @ `88bb77eb` | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Web root | `755 ravi:ravi` (P-07-09-90) | VERIFIED |
| Six P-07-09-88 files | HASH MATCH `96d141b0` | VERIFIED |
| `isAlreadyQueuedPickup` | present on production gateway | VERIFIED |
| RDE318421 `pickup_requested_at` | still `NULL` | VERIFIED |
| Reconcile events | none | VERIFIED |
| AWB / provider shipment | `284931178067631` / `1568724940` | VERIFIED |

P-07-09-88 logic was **deployed**. Classification: **B** (matcher not reached for the live phrase shape) plus **F** (documents service rethrew the rejected wrapper as `ValidationException`, which `layouts/partials/flash` and the show page render as a fatal red banner).

P-07-09-88 required `strcasecmp(trim($message), 'Already in Pickup Queue') === 0`. A provider period or the rejected-result wrapper (`HTTP 400 — Already in Pickup Queue.`) did not persist pickup and flashed the 400.

No second live pickup POST was made to prove punctuation. The live UI string and the still-null local pickup after the overlay are the evidence.

---

## Fix

- `ShiprocketAlreadyQueuedPickup`: HTTP **400** and message **containing** `Already in Pickup Queue`.
- Documents service reconciles that same phrase on a non-retryable rejected result that also contains `400`.
- Other 400s stay fatal.
- Repeat click still skips the provider once local pickup is set.

---

## Not performed

- New Shiprocket pickup/create/AWB/label: **NO — Not performed.**
- RDE318421 data write: **NO — Not performed.**
- Push: **NO — Not performed.**
- Schema / `.env` / migrate / RIN / bulk: **NO — Not performed.**
- `rsync -a` of a 700 temp dir onto the web root: **NO — Not performed.**

---

## Named-file overlay (same ticket)

Individual `install -m 644` of three `4b325809` files. Not `deskd`. Not a directory rsync onto `/var/www/radium-desk`.

| Item | Value |
|------|-------|
| Backup | `/var/www/radium-desk/storage/app/private/overlays/p-07-09-91-20260908T130317Z` |
| Web root after | `755` unchanged |
| Hash verify | 3/3 MATCH `4b325809` |
| `/up` | 200, not LiteSpeed 403 |
| `/login` | 200 |
| RDE318421 | unchanged; pickup still `NULL`; AWB `284931178067631` |

The 400 banner is a POST flash. A later authorized Request Pickup will persist locally and will not flash the 400. That click was **not** made here.
