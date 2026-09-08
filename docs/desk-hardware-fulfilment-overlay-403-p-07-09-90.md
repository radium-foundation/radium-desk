# Public HTTP 403 after Operations overlay — RadiumDesk-P-07-09-90

**Date:** 2026-09-08  
**Prompt ID:** `RadiumDesk-P-07-09-90`  
**Follows:** P-07-09-89 Operations Workspace overlay (`0ff08541` report; UI `a12cde1c`).

Ledger: `docs/cursor-prompt-ledger.md`. Last used ID: **P-07-09-89**. This ticket: **P-07-09-90**.

Classification: **VERIFIED** / **INFERRED** / **UNKNOWN**.

---

## Production / repo at incident start

| Item | Value | Class |
|------|-------|--------|
| Workspace | `/Users/ravi/RadiumWebsites/radium-desk-pos-release` | VERIFIED |
| Branch | `main` @ `0ff08541` (ahead of `origin/main` `931b6b59` by 15) | VERIFIED |
| Remote | `git@github.com:radium-foundation/radium-desk.git` | VERIFIED |
| Server | `srv1910783` / `187.127.129.16` `/var/www/radium-desk` | VERIFIED |
| Latest overlay | `p-07-09-89-20260908T124520Z` (18:15 IST) | VERIFIED |
| P-07-09-89 UI files | present; sample hashes MATCH `a12cde1c` | VERIFIED |
| P-07-09-88 pickup files | present; hashes MATCH `96d141b0` | VERIFIED |

Laravel kernel (CLI as `ravi`): `/up` 200, `/login` 200, authenticated Operations/show 200. Application code was healthy.

---

## 403 source

Public `/`, `/login`, `/up`, `/dashboard`, `/inventory/hardware-fulfilments` all returned the same **1240-byte LiteSpeed HTML** (`Access to this resource on the server is denied!`).

| Signal | Observation | Class |
|--------|-------------|--------|
| `server` on HTTPS | `cloudflare` + `x-turbo-charged-by: LiteSpeed` | VERIFIED |
| Same body via `http://127.0.0.1` + `Host: desk.radiumbox.com` | `server: LiteSpeed` 403, no Cloudflare | VERIFIED |
| Laravel kernel | 200 | VERIFIED |
| LiteSpeed workers | `nobody` | VERIFIED |
| lsphp | `ravi` | VERIFIED |
| `/var/www/radium-desk` | `drwx------` (`700`) `ravi:ravi` mtime **18:15 IST** | VERIFIED |
| `/var/www/radium-desk/public` | `755`, `.htaccess` present | VERIFIED |
| Other project dirs at depth 2 | only the **root** was `700` | VERIFIED |

`nobody` cannot traverse a `700` project root to `public/index.php`. PHP CLI and lsphp run as `ravi`, so artisan/kernel stayed 200.

This is **not** Laravel middleware, WAF rule content, Cloudflare Bot Fight as the primary layer, or an `app.css`/route bug. Cloudflare forwarded LiteSpeed’s 403 (`cf-cache-status: DYNAMIC`).

---

## Why P-07-09-89 caused it

P-07-09-89 staged `git archive a12cde1c` into `mktemp -d` (mode **700**), then:

```
rsync -av --no-owner --no-group "$STAGE"/ ravi@host:/var/www/radium-desk/
```

`rsync -a` includes `-p`. Syncing `STAGE/` onto the dest **root** copies the stage directory mode onto `/var/www/radium-desk`. Overlay time `12:45:20Z` = **18:15 IST** matches the directory mtime.

Application files from the archive were `755`/`644` as expected. Pickup services were not copied.

---

## Correction

Smallest safe change. **No rollback** of P-07-09-89 UI files. **No WAF/auth change.**

```
chmod 755 /var/www/radium-desk
```

Result: `drwxr-xr-x`.

---

## Verification after chmod

| Check | Result |
|-------|--------|
| Local Mac `/` | 302 |
| Local Mac `/login` | 200 |
| Local Mac `/up` | 200 |
| Local Mac `/dashboard` | 302 (login) |
| Local Mac `/inventory/hardware-fulfilments` | 302 (login) |
| Server public same paths | 302 / 200 / 200 / 302 / 302 |
| Localhost Host-header `/up` `/login` | 200 |
| User 2 Operations | 200; Hardware Operations + four pills + Work/Open/Awaiting; no Create All |
| User 2 Hardware dashboard | 200 |
| User 2 show `/inventory/hardware-fulfilments/1` | 200; 11-step rail; AWB `284931178067631` |
| P-07-09-88 hashes | MATCH `96d141b0` |
| P-07-09-89 UI hashes | MATCH `a12cde1c` |

RDE318421 unchanged: HF `1` / `awb_assigned` / provider `1568724940` / courier `Delhivery_Surface/15084` / AWB `284931178067631` / label present / evidence 2 / pickup local empty / manifest null / `updated_at` `2026-09-08 17:54:42`.

---

## Future overlay rule

Do **not** `rsync -a` a `mktemp -d` tree onto the application root. Exclude destination-directory permission (`rsync -a --no-perms` is too broad) or `chmod 755` the stage root before rsync, or rsync only named files without copying `.` mode.

---

## Safety

No fulfilment / serial / invoice / shipment / AWB / pickup / manifest writes. No Shiprocket. No RIN / rdservice.in change. No WAF disable. No `.env`. Application overlay not rolled back.

**Pushed:** NO — Not performed.
